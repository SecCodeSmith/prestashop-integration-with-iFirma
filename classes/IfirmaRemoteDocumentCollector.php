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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';

/**
 * Reads the documents iFirma lists for one customer: yearly windows from today back to the day the
 * customer registered (ten years at most), newest first, each window by contractor identifier and then
 * by NIP, page after page, within a budget of list calls.
 *
 * It makes every list call of a remote count and does nothing else. Beyond what IfirmaApiClient does with
 * each call, it reads no Db or Configuration, has no clock of its own and counts nothing:
 * IfirmaCustomerContractorService hands it everything it needs and tallies what it returns.
 */
class IfirmaRemoteDocumentCollector
{
    /** List calls one collection may make. */
    const CALL_BUDGET = 20;
    /** iloscNaStronie of every list call. */
    const PAGE_SIZE = 100;
    /** How far back a collection looks at most. */
    const MAX_YEARS = 10;
    /** Longest window of one list call, in days. */
    const WINDOW_DAYS = 365;

    /** How reading a window or a filter ended: every page of it was read. */
    const READ = 'read';
    /** The call budget ran out before the next list call. */
    const OUT_OF_CALLS = 'out_of_calls';
    /** A list call failed; $error says why. */
    const FAILED = 'failed';

    /** @var IfirmaApiClient */
    private $client;

    /** @var array the rows of every list answer of the collection in progress, in the order read */
    private $rows = array();

    /** @var int the list calls made so far by the collection in progress */
    private $calls = 0;

    /** @var bool false once iFirma has answered that it does not know the contractor identifier */
    private $kontrahentKnown = true;

    /** @var string the message of the list call that failed */
    private $error = '';

    /**
     * @param IfirmaApiClient $client
     */
    public function __construct(IfirmaApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Reads the customer's documents from today back to the day of registration.
     *
     * @param string $identifier   contractor identifier of the customer (the kontrahent filter)
     * @param string $nip          NIP of the customer, '' for none (the nipKontrahenta filter)
     * @param int    $now          unix timestamp of today
     * @param mixed  $registeredAt date_add of the customer: no window starts before its day; a missing or
     *                             unreadable date, or one more than ten years back, counts as ten years back
     * @return array ok, error, docs, calls, complete, period_from, period_to
     *               - docs: the rows of every list answer in the order read, whatever they are (a document
     *                 can come more than once, a row can be no document); empty when ok is false
     *               - calls: the list calls made, the one that failed included
     *               - complete: false when the call budget ran out before every window was read
     *               - period_from, period_to: the days the windows read in full cover (Y-m-d), '' for none
     *               - error: the message of the list call that failed, '' when ok
     */
    public function collect($identifier, $nip, $now, $registeredAt)
    {
        $this->rows = array();
        $this->calls = 0;
        $this->kontrahentKnown = true;
        $today = new DateTime('@' . $now);
        $today->setTime(0, 0, 0);
        $earliest = self::earliest($today, $registeredAt);

        $coveredFrom = '';
        $to = clone $today;
        while ($to >= $earliest) {
            $from = clone $to;
            $from->modify('-' . (self::WINDOW_DAYS - 1) . ' days');
            if ($from < $earliest) {
                $from = clone $earliest;
            }
            $end = $this->readWindow($from, $to, $identifier, $nip);
            if ($end === self::FAILED) {
                return $this->failure();
            }
            if ($end === self::OUT_OF_CALLS) {
                return $this->answer(false, $coveredFrom, $today);
            }
            $coveredFrom = $from->format('Y-m-d');
            $to = clone $from;
            $to->modify('-1 day');
        }

        return $this->answer(true, $coveredFrom, $today);
    }

    /**
     * @param DateTime $today
     * @param mixed    $registeredAt date_add of the customer
     * @return DateTime the first day to read: the day of registration, but no more than MAX_YEARS back
     *                  and not after today
     */
    private static function earliest(DateTime $today, $registeredAt)
    {
        $earliest = clone $today;
        $earliest->modify('-' . self::MAX_YEARS . ' years');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $registeredAt, $m)) {
            $registered = DateTime::createFromFormat('Y-m-d H:i:s', $m[1] . ' 00:00:00', new DateTimeZone('UTC'));
            if ($registered && $registered > $earliest) {
                $earliest = $registered;
            }
        }
        if ($earliest > $today) {
            $earliest = clone $today;
        }

        return $earliest;
    }

    /**
     * Reads one window: by contractor identifier (until iFirma has said it does not know it), then by NIP.
     *
     * @param DateTime $from       first day of the window
     * @param DateTime $to         last day of the window
     * @param string   $identifier
     * @param string   $nip
     * @return string READ, OUT_OF_CALLS or FAILED
     */
    private function readWindow(DateTime $from, DateTime $to, $identifier, $nip)
    {
        $base = array(
            'dataOd' => $from->format('Y-m-d'),
            'dataDo' => $to->format('Y-m-d'),
            'iloscNaStronie' => self::PAGE_SIZE,
        );

        $queries = array();
        if ($this->kontrahentKnown) {
            $queries[] = array('kontrahent' => $identifier);
        }
        if ($nip !== '') {
            $queries[] = array('nipKontrahenta' => $nip);
        }

        foreach ($queries as $filter) {
            $end = $this->readFilter(array_merge($base, $filter), isset($filter['kontrahent']));
            if ($end !== self::READ) {
                return $end;
            }
        }

        return self::READ;
    }

    /**
     * Reads the pages of one filter of one window, up to the first short page. When iFirma answers the
     * first page of the contractor filter with Kod 201 about an unknown contractor, the filter ends
     * there and is not asked again by this collection; any other failed call fails the collection.
     *
     * @param array $params       dataOd, dataDo, iloscNaStronie and the filter
     * @param bool  $isKontrahent whether the filter is the contractor identifier
     * @return string READ, OUT_OF_CALLS or FAILED
     */
    private function readFilter(array $params, $isKontrahent)
    {
        for ($page = 1;; ++$page) {
            if ($this->calls >= self::CALL_BUDGET) {
                return self::OUT_OF_CALLS;
            }
            if ($page > 1) {
                $params['strona'] = $page;
            }
            ++$this->calls;
            $rows = $this->client->listInvoices($params, 'faktura');
            if ($rows === null) {
                if ($isKontrahent && $page === 1 && $this->contractorFilterUnknown()) {
                    $this->kontrahentKnown = false;

                    return self::READ;
                }
                $this->error = (string) $this->client->getLastError();

                return self::FAILED;
            }
            foreach ($rows as $row) {
                $this->rows[] = $row;
            }
            if (count($rows) < self::PAGE_SIZE) {
                return self::READ;
            }
        }
    }

    /**
     * @param bool     $complete    whether every window was read
     * @param string   $coveredFrom first day of the oldest window read in full, '' for none
     * @param DateTime $today
     * @return array the answer of collect() when no list call failed
     */
    private function answer($complete, $coveredFrom, DateTime $today)
    {
        return array(
            'ok' => true,
            'error' => '',
            'docs' => $this->rows,
            'calls' => $this->calls,
            'complete' => $complete,
            'period_from' => $coveredFrom,
            'period_to' => $coveredFrom === '' ? '' : $today->format('Y-m-d'),
        );
    }

    /**
     * @return array the answer of collect() when a list call failed: no documents and no period
     */
    private function failure()
    {
        return array(
            'ok' => false,
            'error' => $this->error,
            'docs' => array(),
            'calls' => $this->calls,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        );
    }

    /**
     * @return bool true when iFirma answered Kod 201 about an unknown contractor
     */
    private function contractorFilterUnknown()
    {
        return (int) $this->client->getLastKod() === 201
            && mb_stripos((string) $this->client->getLastError(), 'kontrahent') !== false;
    }
}
