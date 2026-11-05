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

use PHPUnit\Framework\TestCase;

/**
 * IfirmaCustomerInvoiceProfile: the names and e-mail of a customer, and the company, phone and NIP of the
 * latest invoice address, with the fallbacks the contractor search form starts from. CustomerContractorServiceTest
 * pins the same answers through defaultCriteria(), customerNip() and remoteCount().
 */
class IfirmaCustomerInvoiceProfileTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    // ---- fixtures ---------------------------------------------------------------------------

    /** A customer object that is not in the registry. */
    private function customer(array $props = array())
    {
        $c = new Customer();
        foreach ($props as $k => $v) {
            $c->$k = $v;
        }

        return $c;
    }

    /** An address object that is not in the registry. */
    private function addressObject(array $props)
    {
        $a = new Address();
        foreach ($props as $k => $v) {
            $a->$k = $v;
        }

        return $a;
    }

    private function profile(array $addresses, array $customer = array())
    {
        return new IfirmaCustomerInvoiceProfile($this->customer($customer), $addresses);
    }

    /** Customer 7 in the registry, for load(). */
    private function seedCustomer(array $props = array())
    {
        $c = $this->customer(array_merge(array('id' => 7, 'firstname' => 'Jan', 'lastname' => 'Kowalski', 'email' => 'jan@example.test'), $props));

        return Customer::register($c);
    }

    private function seedAddress($id, array $props = array(), $idCustomer = 7)
    {
        $a = $this->addressObject(array_merge(array('id' => $id, 'id_customer' => $idCustomer), $props));

        return Address::register($a);
    }

    private function seedOrder($id, $date, $idAddress, $idCustomer = 7)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = $idCustomer;
        $o->date_add = $date;
        $o->id_address_invoice = $idAddress;

        return Order::register($o);
    }

    /**
     * Counts the reads of load(): the runs of Order::getCustomerOrders() and the language id of every
     * Customer::getAddresses() call.
     *
     * @return stdClass orders (int), languages (array)
     */
    private function recordReads()
    {
        $log = new stdClass();
        $log->orders = 0;
        $log->languages = array();
        Order::$customerOrdersHook = function ($idCustomer, $rows) use ($log) {
            ++$log->orders;

            return $rows;
        };
        Customer::$addressesHook = function ($idLang, $rows) use ($log) {
            $log->languages[] = $idLang;

            return $rows;
        };

        return $log;
    }

    // ---- the customer -----------------------------------------------------------------------

    public function testTheCustomerNamesAndEmailAreTrimmed()
    {
        $profile = $this->profile(array(), array('firstname' => "  Jan\t", 'lastname' => "\nKowalski ", 'email' => ' jan@example.test '));

        $this->assertSame('Jan', $profile->firstname());
        $this->assertSame('Kowalski', $profile->lastname());
        $this->assertSame('jan@example.test', $profile->email());
    }

    public function testACustomerWithoutDataAnswersEmptyStrings()
    {
        $profile = $this->profile(array());

        $this->assertSame('', $profile->firstname());
        $this->assertSame('', $profile->lastname());
        $this->assertSame('', $profile->email());
        $this->assertSame('', $profile->company());
        $this->assertSame('', $profile->phone());
        $this->assertSame('', $profile->nip());
        $this->assertSame('', $profile->tenDigitNip());
    }

    // ---- company ----------------------------------------------------------------------------

    /** @return array label => array(company of the latest address, company of the customer, expected company) */
    public static function companyProvider()
    {
        return array(
            'the address company, trimmed' => array("  ACME Sp. z o.o.\t", 'Customer Co', 'ACME Sp. z o.o.'),
            'no address company: the customer company, trimmed' => array('', '  Customer Co ', 'Customer Co'),
            'a blank address company counts as none' => array(" \t ", 'Customer Co', 'Customer Co'),
            'an unset address company counts as none' => array(null, 'Customer Co', 'Customer Co'),
            'a company called 0 is kept' => array('0', 'Customer Co', '0'),
            'no company anywhere' => array('', null, ''),
            'a blank customer company counts as none' => array('', " \t", ''),
        );
    }

    /**
     * @dataProvider companyProvider
     */
    public function testCompany($addressCompany, $customerCompany, $expected)
    {
        $profile = $this->profile(
            array($this->addressObject(array('company' => $addressCompany))),
            array('company' => $customerCompany)
        );

        $this->assertSame($expected, $profile->company());
    }

    public function testWithoutAddressesTheCompanyIsTheCustomersAndTheRestIsEmpty()
    {
        $profile = $this->profile(array(), array('company' => ' Customer Co '));

        $this->assertSame('Customer Co', $profile->company());
        $this->assertSame('', $profile->phone());
        $this->assertSame('', $profile->nip());
        $this->assertSame('', $profile->tenDigitNip());
    }

    // ---- phone ------------------------------------------------------------------------------

    /** @return array label => array(phone, mobile phone, expected phone) */
    public static function phoneProvider()
    {
        return array(
            'the phone, trimmed' => array(' 600 100 200 ', '500 000 000', '600 100 200'),
            'no phone: the mobile phone, trimmed' => array('', ' 500 000 000 ', '500 000 000'),
            'a blank phone counts as none' => array(" \t", '500 000 000', '500 000 000'),
            'an unset phone counts as none' => array(null, '500 000 000', '500 000 000'),
            'a phone called 0 is kept' => array('0', '500 000 000', '0'),
            'neither' => array('', '', ''),
            'neither is set' => array(null, null, ''),
        );
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testPhone($phone, $mobile, $expected)
    {
        $profile = $this->profile(array($this->addressObject(array('phone' => $phone, 'phone_mobile' => $mobile))));

        $this->assertSame($expected, $profile->phone());
    }

    /** Company and phone come from the latest address only; they do not look further back. */
    public function testCompanyAndPhoneAreNotTakenFromAnOlderAddress()
    {
        $profile = $this->profile(
            array(
                $this->addressObject(array('company' => '', 'phone' => '', 'phone_mobile' => '')),
                $this->addressObject(array('company' => 'Old Co', 'phone' => '111', 'phone_mobile' => '112')),
            ),
            array('company' => 'Customer Co')
        );

        $this->assertSame('Customer Co', $profile->company());
        $this->assertSame('', $profile->phone());
    }

    // ---- NIP --------------------------------------------------------------------------------

    /**
     * @return array label => array(vat_number of the latest address, vat_number of an older one,
     *               nip() for the form, tenDigitNip())
     */
    public static function nipProvider()
    {
        return array(
            'ten digits' => array('123-456-32-18', '525-224-84-81', '1234563218', '1234563218'),
            'letters and spaces are dropped' => array('PL 123 456 32 18', '525-224-84-81', '1234563218', '1234563218'),
            'five digits are kept as typed, whatever the length' => array('12-345', '525-224-84-81', '12345', '5252248481'),
            'nine digits are kept too' => array('123456789', '525-224-84-81', '123456789', '5252248481'),
            'eleven digits are kept too' => array('12345678901', '525-224-84-81', '12345678901', '5252248481'),
            'a lone 0 is kept' => array('0', '525-224-84-81', '0', '5252248481'),
            'no digits: the NIP of an older address' => array('n/a', '525-224-84-81', '5252248481', '5252248481'),
            'empty: the NIP of an older address' => array('', '525-224-84-81', '5252248481', '5252248481'),
            'unset: the NIP of an older address' => array(null, '525-224-84-81', '5252248481', '5252248481'),
            'five digits and no older NIP' => array('12-345', '123', '12345', ''),
            'no digits anywhere' => array('n/a', '', '', ''),
        );
    }

    /**
     * @dataProvider nipProvider
     */
    public function testNipAndTenDigitNip($latestVat, $olderVat, $expectedNip, $expectedTenDigitNip)
    {
        $profile = $this->profile(array(
            $this->addressObject(array('vat_number' => $latestVat)),
            $this->addressObject(array('vat_number' => $olderVat)),
        ));

        $this->assertSame($expectedNip, $profile->nip());
        $this->assertSame($expectedTenDigitNip, $profile->tenDigitNip());
    }

    public function testTenDigitNipIsTheFirstAddressWithExactlyTenDigits()
    {
        $profile = $this->profile(array(
            $this->addressObject(array('vat_number' => '')),
            $this->addressObject(array('vat_number' => '12345678901')),
            $this->addressObject(array('vat_number' => '123456789')),
            $this->addressObject(array('vat_number' => '222-222-22-22')),
            $this->addressObject(array('vat_number' => '5252248481')),
        ));

        $this->assertSame('2222222222', $profile->tenDigitNip());
        $this->assertSame('2222222222', $profile->nip());
    }

    // ---- the latest address -----------------------------------------------------------------

    public function testTheLatestAddressIsTheFirstOfTheListWhateverItsKey()
    {
        $profile = $this->profile(array(
            'newest' => $this->addressObject(array('company' => 'Newest Co', 'phone' => '1', 'vat_number' => '12-345')),
            'older' => $this->addressObject(array('company' => 'Older Co', 'phone' => '2', 'vat_number' => '5252248481')),
        ));

        $this->assertSame('Newest Co', $profile->company());
        $this->assertSame('1', $profile->phone());
        $this->assertSame('12345', $profile->nip());
        $this->assertSame('5252248481', $profile->tenDigitNip());
    }

    // ---- load(): what is read, in which order, how often -----------------------------------

    public function testLoadAnswersFromTheCustomerAndTheLatestOrderAddress()
    {
        $this->seedCustomer(array('company' => 'Customer Co'));
        $this->seedAddress(1, array('company' => 'Old Co', 'phone' => '111', 'vat_number' => '111-111-11-11'));
        $this->seedAddress(2, array('company' => ' ACME Sp. z o.o. ', 'phone' => '', 'phone_mobile' => '600 100 200', 'vat_number' => '123-456-32-18'));
        $this->seedOrder(10, '2026-01-01 10:00:00', 1);
        $this->seedOrder(11, '2026-05-01 10:00:00', 2);

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame('Jan', $profile->firstname());
        $this->assertSame('Kowalski', $profile->lastname());
        $this->assertSame('jan@example.test', $profile->email());
        $this->assertSame('ACME Sp. z o.o.', $profile->company());
        $this->assertSame('600 100 200', $profile->phone());
        $this->assertSame('1234563218', $profile->nip());
        $this->assertSame('1234563218', $profile->tenDigitNip());
    }

    public function testLoadOfAnUnknownCustomerAnswersEmptyStrings()
    {
        $profile = IfirmaCustomerInvoiceProfile::load(99);

        $this->assertSame('', $profile->firstname());
        $this->assertSame('', $profile->company());
        $this->assertSame('', $profile->phone());
        $this->assertSame('', $profile->nip());
        $this->assertSame('', $profile->tenDigitNip());
        $this->assertSame(array(99), Customer::$loads);
        $this->assertSame(array(), Address::$loads);
    }

    /** Whatever is asked afterwards, load() has read the customer and the addresses once. */
    public function testLoadReadsTheCustomerAndTheAddressesOnce()
    {
        $this->seedCustomer(array('company' => 'Customer Co'));
        $this->seedAddress(1, array('vat_number' => '525-224-84-81'));
        $this->seedAddress(2, array('company' => 'New Co', 'vat_number' => ''));
        $this->seedOrder(10, '2026-05-01 10:00:00', 2);
        $reads = $this->recordReads();

        $profile = IfirmaCustomerInvoiceProfile::load(7);
        $answers = array(
            $profile->firstname(), $profile->lastname(), $profile->email(),
            $profile->company(), $profile->phone(), $profile->nip(), $profile->tenDigitNip(),
        );

        $this->assertSame(array('Jan', 'Kowalski', 'jan@example.test', 'New Co', '', '5252248481', '5252248481'), $answers);
        $this->assertSame(1, $reads->orders);
        $this->assertSame(array(1), $reads->languages);
        $this->assertSame(array(7), Customer::$loads);
        $this->assertSame(array(2, 1), Address::$loads);
    }

    /** The profile is what was read: a later change of the stored data does not reach it. */
    public function testTheAnswersAreNotReadAgainAfterLoad()
    {
        $this->seedCustomer(array('company' => 'Customer Co'));
        $this->seedAddress(1, array('company' => 'Old Co', 'phone' => '111', 'vat_number' => '525-224-84-81'));
        $profile = IfirmaCustomerInvoiceProfile::load(7);

        Customer::$instances[7]->firstname = 'Anna';
        Address::$instances[1]->company = 'New Co';
        Address::$instances[1]->phone = '222';
        Address::$instances[1]->vat_number = '123-456-32-18';

        $this->assertSame('Jan', $profile->firstname());
        $this->assertSame('Old Co', $profile->company());
        $this->assertSame('111', $profile->phone());
        $this->assertSame('5252248481', $profile->nip());
        $this->assertSame('5252248481', $profile->tenDigitNip());
    }

    /** Orders first, then the customer, its addresses, and last the address objects: the order invoiceAddresses() used. */
    public function testTheReadsRunInTheOrderTheServiceUsedToMakeThem()
    {
        $this->seedCustomer();
        $this->seedAddress(1);
        $this->seedAddress(2);
        $this->seedOrder(10, '2026-05-01 10:00:00', 2);
        $events = array();
        Order::$customerOrdersHook = function ($idCustomer, $rows) use (&$events) {
            $events[] = array('orders', count(Customer::$loads), count(Address::$loads));

            return $rows;
        };
        Customer::$addressesHook = function ($idLang, $rows) use (&$events) {
            $events[] = array('addresses', count(Customer::$loads), count(Address::$loads));

            return $rows;
        };

        IfirmaCustomerInvoiceProfile::load(7);

        // (what ran, customers loaded so far, addresses loaded so far)
        $this->assertSame(array(array('orders', 0, 0), array('addresses', 1, 0)), $events);
        $this->assertSame(array(2, 1), Address::$loads);
    }

    /** A request hands the id over as a string; the queries get an int. */
    public function testTheCustomerIdIsPassedOnAsAnInteger()
    {
        $this->seedCustomer();
        $asked = array();
        Order::$customerOrdersHook = function ($idCustomer, $rows) use (&$asked) {
            $asked[] = $idCustomer;

            return $rows;
        };

        IfirmaCustomerInvoiceProfile::load('7');

        $this->assertSame(array(7), $asked);
        $this->assertSame(array(7), Customer::$loads);
    }

    public function testAddressesAreLoadedOrdersNewestFirstThenOwnNewestFirstEachOnce()
    {
        $this->seedCustomer();
        foreach (array(1, 2, 3, 4) as $id) {
            $this->seedAddress($id, array('company' => 'Co ' . $id));
        }
        $this->seedOrder(10, '2026-01-01 10:00:00', 1);
        $this->seedOrder(11, '2026-03-01 10:00:00', 3);
        $this->seedOrder(12, '2026-05-01 10:00:00', 2);
        $this->seedOrder(13, '2026-04-01 10:00:00', 2);

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        // orders newest first: 12 -> 2, 13 -> 2, 11 -> 3, 10 -> 1; then the own ones: 4, 3, 2, 1
        $this->assertSame(array(2, 3, 1, 4), Address::$loads);
        $this->assertSame('Co 2', $profile->company());
    }

    public function testOrderAddressesComeBeforeTheOwnOnes()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Ordered Co'));
        $this->seedAddress(2, array('company' => 'Second Co'));
        $this->seedAddress(3, array('company' => 'Newest Co'));
        $this->seedOrder(10, '2026-05-01 10:00:00', 1);

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(1, 3, 2), Address::$loads);
        $this->assertSame('Ordered Co', $profile->company());
    }

    public function testWithoutOrdersTheNewestOwnAddressComesFirst()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Old Co'));
        $this->seedAddress(2, array('company' => 'New Co'));

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(2, 1), Address::$loads);
        $this->assertSame('New Co', $profile->company());
    }

    public function testAddressesThatDoNotLoadAreLeftOut()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Existing Co'));
        $this->seedOrder(10, '2026-01-01 10:00:00', 1);
        $this->seedOrder(11, '2026-05-01 10:00:00', 99); // the address was deleted

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(99, 1), Address::$loads);
        $this->assertSame('Existing Co', $profile->company());
    }

    public function testOrdersWithoutAnInvoiceAddressAreLeftOut()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Own Co'));
        $this->seedOrder(10, '2026-01-01 10:00:00', 0);
        $this->seedOrder(11, '2026-02-01 10:00:00', null);
        $this->seedOrder(12, '2026-03-01 10:00:00', '');

        IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(1), Address::$loads);
    }

    public function testTheOrdersAndAddressesOfAnotherCustomerAreNotLoaded()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Own Co'));
        $this->seedAddress(2, array('company' => 'Other Co'), 8);
        $this->seedOrder(10, '2026-05-01 10:00:00', 2, 8);

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(1), Address::$loads);
        $this->assertSame('Own Co', $profile->company());
    }

    public function testAddressIdsAreLoadedAsIntegers()
    {
        // core hands the ids over as strings
        Order::$customerOrdersHook = function ($idCustomer, $rows) {
            foreach ($rows as $i => $row) {
                $rows[$i]['id_address_invoice'] = (string) $row['id_address_invoice'];
            }

            return $rows;
        };
        Customer::$addressesHook = function ($idLang, $rows) {
            foreach ($rows as $i => $row) {
                $rows[$i]['id_address'] = (string) $row['id_address'];
            }

            return $rows;
        };
        $this->seedCustomer();
        foreach (array(1, 2, 3) as $id) {
            $this->seedAddress($id);
        }
        $this->seedOrder(10, '2026-05-01 10:00:00', 2);

        IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(2, 3, 1), Address::$loads);
    }

    // ---- load(): answers that are not rows --------------------------------------------------

    public function testAnExceptionOfTheOrderQueryMeansNoOrderAddresses()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Old Co'));
        $this->seedAddress(2, array('company' => 'Own Co'));
        $this->seedOrder(10, '2026-05-01 10:00:00', 1);
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders unavailable');
        };

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(2, 1), Address::$loads);
        $this->assertSame('Own Co', $profile->company());
    }

    /** Only an Exception of the order query is caught; an Error is not. */
    public function testAnErrorOfTheOrderQueryIsNotSwallowed()
    {
        $this->seedCustomer();
        Order::$customerOrdersHook = function () {
            throw new TypeError('order query broke');
        };

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('order query broke');

        IfirmaCustomerInvoiceProfile::load(7);
    }

    /** @return array label => array(answer) */
    public static function answersThatAreNoRows()
    {
        return array(
            'false' => array(false),
            'null' => array(null),
            'a string' => array('database error'),
        );
    }

    /**
     * @dataProvider answersThatAreNoRows
     */
    public function testAnOrderAnswerThatIsNoArrayMeansNoOrderAddresses($answer)
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Old Co'));
        $this->seedAddress(2, array('company' => 'Own Co'));
        $this->seedOrder(10, '2026-05-01 10:00:00', 1);
        Order::$customerOrdersHook = function () use ($answer) {
            return $answer;
        };

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(2, 1), Address::$loads);
        $this->assertSame('Own Co', $profile->company());
    }

    /**
     * @dataProvider answersThatAreNoRows
     */
    public function testAnAddressAnswerThatIsNoArrayMeansNoOwnAddresses($answer)
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Ordered Co'));
        $this->seedAddress(2, array('company' => 'Own Co'));
        $this->seedOrder(10, '2026-05-01 10:00:00', 1);
        Customer::$addressesHook = function () use ($answer) {
            return $answer;
        };

        $profile = IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(1), Address::$loads);
        $this->assertSame('Ordered Co', $profile->company());
    }

    public function testOwnAddressRowsWithoutAnIdAreIgnored()
    {
        $this->seedCustomer();
        $this->seedAddress(1, array('company' => 'Own Co'));
        Customer::$addressesHook = function ($idLang, $rows) {
            return array_merge(array(array('company' => 'No id'), 'junk'), $rows);
        };

        IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array(1), Address::$loads);
    }

    /** @return array label => array(language of the context, language asked for) */
    public static function contextLanguages()
    {
        return array(
            'an int' => array(3, 3),
            'a numeric string' => array('2', 2),
        );
    }

    /**
     * @dataProvider contextLanguages
     */
    public function testTheOwnAddressesAreAskedForInTheContextLanguage($contextLanguage, $expected)
    {
        $this->seedCustomer();
        $reads = $this->recordReads();
        Context::getContext()->language->id = $contextLanguage;

        IfirmaCustomerInvoiceProfile::load(7);

        $this->assertSame(array($expected), $reads->languages);
    }
}
