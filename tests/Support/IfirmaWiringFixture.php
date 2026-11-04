<?php
/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

/**
 * Shared fixture for the tests that exercise the module entry points (hooks, admin actions)
 * end to end: a configured module, a customer, an order, an in-memory DB view of the
 * receipt / data request tables and one ScriptedTransport used as the default transport of
 * every IfirmaApiClient.
 */
trait IfirmaWiringFixture
{
    /** @var ScriptedTransport */
    protected $t;

    /** @var Ifirma */
    protected $module;

    protected function wiringSetUp()
    {
        IfirmaTestState::resetAll();
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Configuration::updateValue('IFIRMA_USER', 'u@example.com');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
        Configuration::updateValue('IFIRMA_BASE_URL', 'http://ifirma-mock:8001/iapi/');
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturakraj');
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 1);
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
        Configuration::updateValue('IFIRMA_SYNC_INTERVAL_MIN', 15);
        Configuration::updateValue('IFIRMA_CRON_TOKEN', 'cron-token-1');

        $this->t = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($this->t);

        $cur = new Currency();
        $cur->id = 1;
        $cur->iso_code = 'PLN';
        Currency::register($cur);
        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->address1 = 'Prosta 1';
        $a->postcode = '00-001';
        $a->city = 'Warszawa';
        $a->id_country = 14;
        Address::register($a);
        Order::register($this->wiringOrder());

        $ctx = Context::getContext();
        $ctx->customer = Customer::$instances[5];
        $ctx->customer->logged = true;

        $this->wiringScriptDb();
        $this->module = IfirmaTestModuleLoader::loadModule();
    }

    protected function wiringOrder()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_shop = 1;
        $o->id_lang = 1;
        $o->id_currency = 1;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s', time() - 86400);
        $o->total_paid_tax_incl = 123.00;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 123,
            'unit_price_tax_excl' => 123,
            'tax_rate' => 0,
        ));

        return $o;
    }

    /** Makes the finder queries of IfirmaReceipt / IfirmaDataRequest read the in-memory rows. */
    protected function wiringScriptDb()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function ($sql, $m) {
            $found = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $id > $found) {
                    $found = $id;
                }
            }

            return $found ?: false;
        });
        $db->whenExecuteS('/FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)/', function ($sql, $m) {
            $rows = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_customer'] === (int) $m[1] && $row['status'] !== 'error') {
                    $rows[] = array('id_ifirma_receipt' => $id) + $row;
                }
            }

            return $rows;
        });
        $db->whenGetValue('/SELECT id_ifirma_data_request\s+FROM .*id_order = (\d+)\s+AND status = "(\w+)"/s', function ($sql, $m) {
            $found = 0;
            foreach (IfirmaTestState::objects('ifirma_data_request') as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $row['status'] === $m[2] && $id > $found) {
                    $found = $id;
                }
            }

            return $found ?: false;
        });
        $db->whenGetValue('/SELECT COUNT\(\*\) FROM `ps_ifirma_data_request`\s+WHERE id_customer = (\d+)/', function ($sql, $m) {
            $n = 0;
            foreach (IfirmaTestState::objects('ifirma_data_request') as $row) {
                if ((int) $row['id_customer'] === (int) $m[1] && $row['status'] === 'pending') {
                    $n++;
                }
            }

            return $n;
        });
        $db->whenExecuteS('/SELECT \* FROM `ps_ifirma_data_request`\s+WHERE id_order = (\d+)/', function ($sql, $m) {
            $rows = array();
            foreach (array_reverse(IfirmaTestState::objects('ifirma_data_request'), true) as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1]) {
                    $rows[] = array('id_ifirma_data_request' => $id) + $row;
                }
            }

            return $rows;
        });
    }

    protected function listBody(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows))));
    }

    protected function remoteRow(array $over = array())
    {
        return array_merge(array(
            'FakturaId' => 77,
            'PelnyNumer' => '7/2026',
            'DataWystawienia' => date('Y-m-d'),
            'Brutto' => 123.00,
            'Zaplacono' => 0,
            'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_paragon',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => false,
        ), $over);
    }

    protected function queueGuardNone()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array('FakturaId' => 1, 'Brutto' => 5)))));
    }

    protected function queueCreateOk($type = 'fakturakraj')
    {
        $this->t->queue('POST', '#' . $type . '\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
    }

    protected function queueNumber()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow())));
    }

    protected function queuePdf()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => "%PDF-1.4\nfake body\n%%EOF"));
    }

    protected function queueFullSuccess()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();
    }

    protected function posts()
    {
        $p = array();
        foreach ($this->t->calls as $c) {
            if ($c['method'] === 'POST') {
                $p[] = $c;
            }
        }

        return $p;
    }

    protected function listCalls()
    {
        $n = 0;
        foreach ($this->t->calls as $c) {
            if ($c['method'] === 'GET' && strpos($c['url'], 'faktury.json') !== false) {
                $n++;
            }
        }

        return $n;
    }

    /** Stores a receipt row for order 15 and returns the reloaded object. */
    protected function makeReceipt(array $props = array())
    {
        $r = new IfirmaReceipt();
        $defaults = array(
            'id_order' => 15,
            'id_customer' => 5,
            'document_type' => 'fakturakraj',
            'ifirma_id' => '77',
            'ifirma_number' => '7/2026',
            'status' => IfirmaReceipt::STATUS_CREATED,
            'security_token' => 'tok123',
            'issue_date' => date('Y-m-d'),
            'delivery_status' => 'none',
            'last_sync_at' => date('Y-m-d H:i:s'),
        );
        foreach (array_merge($defaults, $props) as $k => $v) {
            $r->$k = $v;
        }
        $r->save();

        return new IfirmaReceipt((int) $r->id);
    }

    protected function makeRequest($status = 'pending', array $data = null)
    {
        $req = new IfirmaDataRequest();
        $req->id_order = 15;
        $req->id_customer = 5;
        $req->status = $status;
        $req->setRequestedData($data !== null ? $data : array(
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '5260250274',
            'firstname' => 'Jan',
            'lastname' => 'Kowalski',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => 14,
        ));
        $req->save();

        return new IfirmaDataRequest((int) $req->id);
    }
}
