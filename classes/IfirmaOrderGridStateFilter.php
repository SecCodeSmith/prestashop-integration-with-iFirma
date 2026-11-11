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

require_once dirname(__FILE__) . '/IfirmaDocumentStateRank.php';
require_once dirname(__FILE__) . '/IfirmaOrderGridIndicator.php';

/**
 * The state filter of the "iFirma" column of the Orders list: the choices the grid offers, the one value the
 * module accepts from the request, and the condition it adds to a query builder. Framework-free: the grid hooks in
 * ifirma.php only wire it into the Symfony grid.
 */
class IfirmaOrderGridStateFilter
{
    /** The filter name in the grid's search criteria: the id of the column the filter belongs to. */
    const FIELD = IfirmaOrderGridIndicator::FIELD;

    /** The bound parameter of the condition. */
    const PARAMETER = 'ifirma_rank';

    /**
     * @param string[] $labels label key (IfirmaDocumentStateRank::states()) => the word for that state
     * @return int[] word => rank, in rank order: the shape of a Symfony ChoiceType 'choices' option
     */
    public static function choices(array $labels)
    {
        $choices = array();
        foreach (IfirmaDocumentStateRank::states() as $state) {
            $key = $state['label_key'];
            $choices[isset($labels[$key]) ? (string) $labels[$key] : $key] = $state['rank'];
        }

        return $choices;
    }

    /**
     * @param mixed $criteria the grid's search criteria
     * @return int|null the chosen rank; null when there is no filter value or it is not an integer from 1 to 10
     */
    public static function rankFrom($criteria)
    {
        if (!is_object($criteria) || !method_exists($criteria, 'getFilters')) {
            return null;
        }
        $filters = $criteria->getFilters();
        if (!is_array($filters) || !array_key_exists(self::FIELD, $filters)) {
            return null;
        }

        return IfirmaDocumentStateRank::parse($filters[self::FIELD]);
    }

    /**
     * Restricts a query builder whose joined newest record has the alias IfirmaOrderGridIndicator::JOIN_ALIAS to the
     * orders in the given state. The rank is bound as an integer, never written into the SQL.
     *
     * @param object $queryBuilder a Doctrine query builder (andWhere, setParameter)
     * @param int    $rank         a value from IfirmaDocumentStateRank::parse()
     */
    public static function apply($queryBuilder, $rank)
    {
        $queryBuilder->andWhere('(' . IfirmaDocumentStateRank::sql(IfirmaOrderGridIndicator::JOIN_ALIAS) . ') = :' . self::PARAMETER);
        $queryBuilder->setParameter(self::PARAMETER, (int) $rank, PDO::PARAM_INT);
    }
}
