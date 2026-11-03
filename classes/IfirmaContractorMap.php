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

require_once dirname(__FILE__) . '/IfirmaSchema.php';

/**
 * One shop customer linked to one iFirma contractor (Identyfikator).
 */
class IfirmaContractorMap extends ObjectModel
{
    const BY_MODULE = 'module';
    const BY_NIP = 'nip';
    const BY_NAME = 'name';
    const BY_EMAIL = 'email';
    const BY_IMPORT_NIP = 'import-nip';
    const BY_IMPORT_EMAIL = 'import-email';
    const BY_IMPORT_NAME = 'import-name';
    const BY_MANUAL = 'manual';
    /** Contractor iFirma attached to an issued document when the module could not reference one. */
    const BY_DOCUMENT = 'document';

    /** @var int */
    public $id_ifirma_contractor_map;

    /** @var int */
    public $id_customer;

    /** @var string */
    public $ifirma_identifier = '';

    /** @var string */
    public $matched_by = '';

    /** @var string */
    public $ifirma_name = '';

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = array(
        'table' => 'ifirma_contractor_map',
        'primary' => 'id_ifirma_contractor_map',
        'fields' => array(
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'ifirma_identifier' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64),
            'matched_by' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'ifirma_name' => array('type' => self::TYPE_STRING, 'size' => 150),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * @param int $id
     * @return IfirmaContractorMap|null
     */
    private static function load($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $map = new self($id);

        return Validate::isLoadedObject($map) ? $map : null;
    }

    /**
     * @param int $idCustomer
     * @return IfirmaContractorMap|null
     */
    public static function getByCustomer($idCustomer)
    {
        IfirmaSchema::ensureContractorMapTable();

        return self::load(Db::getInstance()->getValue(
            'SELECT id_ifirma_contractor_map FROM `' . _DB_PREFIX_ . 'ifirma_contractor_map`
             WHERE id_customer = ' . (int) $idCustomer
        ));
    }

    /**
     * @param string $identifier
     * @return IfirmaContractorMap|null first mapping that points at the identifier
     */
    public static function getByIdentifier($identifier)
    {
        IfirmaSchema::ensureContractorMapTable();

        return self::load(Db::getInstance()->getValue(
            'SELECT id_ifirma_contractor_map FROM `' . _DB_PREFIX_ . 'ifirma_contractor_map`
             WHERE ifirma_identifier = "' . pSQL((string) $identifier) . '"
             ORDER BY id_ifirma_contractor_map ASC'
        ));
    }

    /**
     * Upsert: a second link for the same customer replaces the first.
     *
     * @param int    $idCustomer
     * @param string $identifier
     * @param string $matchedBy
     * @param string $name
     * @return IfirmaContractorMap
     */
    public static function link($idCustomer, $identifier, $matchedBy, $name = '')
    {
        $map = self::getByCustomer($idCustomer);
        if (!$map) {
            $map = new self();
            $map->id_customer = (int) $idCustomer;
        }
        $map->ifirma_identifier = mb_substr((string) $identifier, 0, 64);
        $map->matched_by = mb_substr((string) $matchedBy, 0, 16);
        $map->ifirma_name = mb_substr((string) $name, 0, 150);
        $map->save();

        return $map;
    }

    /**
     * @param int $idCustomer
     * @return bool true when a mapping was removed
     */
    public static function unlinkCustomer($idCustomer)
    {
        $map = self::getByCustomer($idCustomer);

        return $map ? (bool) $map->delete() : false;
    }

    /**
     * @return array matched_by => count
     */
    public static function countByMatchType()
    {
        IfirmaSchema::ensureContractorMapTable();
        $rows = Db::getInstance()->executeS(
            'SELECT matched_by, COUNT(*) AS total FROM `' . _DB_PREFIX_ . 'ifirma_contractor_map`
             GROUP BY matched_by ORDER BY matched_by ASC'
        );
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(string) $row['matched_by']] = (int) $row['total'];
            }
        }

        return $out;
    }

    /**
     * Forget every link.
     *
     * @return bool
     */
    public static function truncateAll()
    {
        IfirmaSchema::ensureContractorMapTable();

        return (bool) Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'ifirma_contractor_map`');
    }
}
