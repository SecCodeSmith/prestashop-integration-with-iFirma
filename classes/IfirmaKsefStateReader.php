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
require_once dirname(__FILE__) . '/IfirmaKsefRemoteState.php';

/**
 * Reads a document's KSeF state from iFirma: one call to the document detail (the invoice data carries KsefStatus
 * and KsefNumer since iFirma's API change of 2026-02-01). A missing key reads as an empty string.
 */
class IfirmaKsefStateReader
{
    /** @var IfirmaApiClient */
    private $client;

    /**
     * @param IfirmaApiClient $client
     */
    public function __construct(IfirmaApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $type document type, as stored on the receipt
     * @param string $id   iFirma's document id
     * @return IfirmaKsefRemoteState
     */
    public function read($type, $id)
    {
        $detail = $this->client->getDetail($type, $id);
        if ($detail === null || $this->client->getLastKod() !== null || !is_array($detail['data'])) {
            return IfirmaKsefRemoteState::unreadable($this->failure(), $this->client->isRateLimited());
        }

        $data = $detail['data'];
        $node = (isset($data['response']) && is_array($data['response'])) ? $data['response'] : $data;

        return IfirmaKsefRemoteState::reported(
            isset($node['KsefStatus']) ? (string) $node['KsefStatus'] : '',
            isset($node['KsefNumer']) ? (string) $node['KsefNumer'] : ''
        );
    }

    /**
     * @return string
     */
    private function failure()
    {
        $error = trim((string) $this->client->getLastError());

        return $error !== '' ? $error : 'The KSeF state of the document could not be read.';
    }
}
