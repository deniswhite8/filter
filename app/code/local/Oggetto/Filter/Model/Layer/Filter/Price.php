<?php
/**
 * Oggetto Filter extension for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade
 * the Oggetto Filter module to newer versions in the future.
 * If you wish to customize the Oggetto Filter module for your needs
 * please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Oggetto
 * @package    Oggetto_Filter
 * @copyright  Copyright (C) 2014 Oggetto Web (http://oggettoweb.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
?>
<?php
/**
 * Price filter attribute
 *
 * @category   Oggetto
 * @package    Oggetto_Filter
 * @subpackage Model
 * @author     Denis Belov <dbelov@oggettoweb.com>
 */

/**
 * @method Mage_Catalog_Model_Layer_Filter_Price setInterval(array)
 * @method array getInterval()
 */
class Oggetto_Filter_Model_Layer_Filter_Price extends Mage_Catalog_Model_Layer_Filter_Price
{
    /**
     * Filter values
     * @var array
     */
    protected $_filterValues;

    /**
     * Initialize filter items
     *
     * @return  Oggetto_Filter_Model_Layer_Filter_Category
     */
    protected function _initItems()
    {
        $this->_items = Mage::getSingleton('oggetto_filter/layer_filter_data')->_initItems($this,
            $this->_getItemsData());
        return $this;
    }

    /**
     * Create filter item object
     *
     * @param string $label    Label
     * @param mixed  $value    Value
     * @param int    $selected Selected
     * @param int    $count    Count
     *
     * @return Mage_Catalog_Model_Layer_Filter_Item
     */
    protected function _createItem($label, $value, $selected = 0, $count=0)
    {
        return Mage::getSingleton('oggetto_filter/layer_filter_data')->_createItem($this, $label, $value,
            $selected, $count);
    }





    /**
     * Get price range for building filter steps
     *
     * @return int
     */
    public function getPriceRange()
    {
        $range = $this->getData('price_range');
        if (!$range) {
            $currentCategory = Mage::registry('current_category_filter');
            if ($currentCategory) {
                $range = $currentCategory->getFilterPriceRange();
            } else {
                $range = $this->getLayer()->getCurrentCategory()->getFilterPriceRange();
            }

            $maxPrice = $this->getMaxPriceInt();
            if (!$range) {
                $calculation = Mage::app()->getStore()->getConfig(self::XML_PATH_RANGE_CALCULATION);
                if ($calculation == self::RANGE_CALCULATION_AUTO) {
                    $range = pow(10, (strlen(floor($maxPrice)) - 1));
                } else {
                    $range = (float)Mage::app()->getStore()->getConfig(self::XML_PATH_RANGE_STEP);
                }
            }

            $this->setData('price_range', $range);
        }

        return $range;
    }

    /**
     * Get maximum price from layer products set
     *
     * @return float
     */
    public function getMaxPriceInt()
    {
        $maxPrice = $this->getData('max_price_int');
        if (is_null($maxPrice)) {
            $collection = $this->getLayer()->getProductCollection();
            $maxPrice = $collection->clearPriceFilters()->getMaxPrice();
            $collection->retrievePriceFilters();
            $maxPrice = floor($maxPrice);
            $this->setData('max_price_int', $maxPrice);
        }

        return $maxPrice;
    }

    /**
     * Get minimum price from layer products set
     *
     * @return float
     */
    public function getMinPriceInt()
    {
        $minPrice = $this->getData('min_price_int');
        if (is_null($minPrice)) {
            $collection = $this->getLayer()->getProductCollection();
            $minPrice = $collection->clearPriceFilters()->getMinPrice();
            $collection->retrievePriceFilters();
            $minPrice = floor($minPrice);
            $this->setData('min_price_int', $minPrice);
        }

        return $minPrice;
    }


    /**
     * Get data generated by algorithm for build price filter items
     *
     * @return array
     */
    protected function _getCalculatedItemsData()
    {
        /** @var $algorithmModel Mage_Catalog_Model_Layer_Filter_Price_Algorithm */
        $algorithmModel = Mage::getSingleton('catalog/layer_filter_price_algorithm');
        $collection = $this->getLayer()->getProductCollection()->clearPriceFilters();

        $minPrice = $collection->getMinPrice();
        $maxPrice = $collection->getMaxPrice();

        $appliedInterval = array($minPrice, $maxPrice);

        if ($appliedInterval
            && $collection->getPricesCount() <= $this->getIntervalDivisionLimit()
        ) {
            return array();
        }

        $algorithmModel->setPricesModel($this)->setStatistics(
            $minPrice,
            $maxPrice,
            $collection->getPriceStandardDeviation(),
            $collection->getPricesCount()
        );
        $collection->retrievePriceFilters();

        if ($appliedInterval) {
            if ($appliedInterval[0] == $appliedInterval[1] || $appliedInterval[1] === '0') {
                return array();
            }
            $algorithmModel->setLimits($appliedInterval[0], $appliedInterval[1]);
        }

        $items = array();
        foreach ($algorithmModel->calculateSeparators() as $separator) {
            $items[] = array(
                'label' => $this->_renderRangeLabel($separator['from'], $separator['to']),
                'value' => (($separator['from'] == 0) ? '' : $separator['from'])
                    . '-' . $separator['to'],
                'count' => $separator['count'],
                'selected' => in_array($separator['from'], $this->_filterValues)
            );
        }

        return $items;
    }


    /**
     * Get data for build price filter items
     *
     * @return array
     */
    protected function _getItemsData()
    {
        if (Mage::app()->getStore()->getConfig(self::XML_PATH_RANGE_CALCULATION) == self::RANGE_CALCULATION_IMPROVED) {
            return $this->_getCalculatedItemsData();
        }

        $range      = $this->getPriceRange();
        $dbRanges   = $this->getRangeItemCounts($range);
        $data       = array();

        if ($this->_filterValues) {
            foreach ($this->_filterValues as $fromPrice) {
                $index = (int) floor((round(($fromPrice) * 1, 2)) / $range) + 1;
                if (!isset($dbRanges[$index])) {
                    $dbRanges[$index] = 0;
                }
            }
        }

        ksort($dbRanges);
        if (!empty($dbRanges)) {
            // $lastIndex = floor((round(($this->getMaxPriceInt()) * 1, 2)) / $range) + 1;
            $lastIndex = array_keys($dbRanges);
            $lastIndex = $lastIndex[count($lastIndex) - 1];

            foreach ($dbRanges as $index => $count) {
                $fromPrice = ($index == 1) ? '' : (($index - 1) * $range);
                $toPrice = ($index == $lastIndex) ? '' : ($index * $range);

                $data[$fromPrice] = array(
                    'label' => $this->_renderRangeLabel($fromPrice, $toPrice),
                    'value' => $fromPrice . '-' . $toPrice,
                    'count' => $count,
                    'selected' => (is_array($this->_filterValues) && in_array($fromPrice, $this->_filterValues))
                );
            }
        }

        return $data;
    }

    /**
     * Apply price range filter
     *
     * @param Zend_Controller_Request_Abstract         $request     Request
     * @param Mage_Catalog_Block_Layer_Filter_Abstract $filterBlock Filter block
     *
     * @return Mage_Catalog_Model_Layer_Filter_Price
     */
    public function apply(Zend_Controller_Request_Abstract $request, $filterBlock)
    {
        $separator = Mage::helper('oggetto_filter/data')->getSeparator();

        /**
         * Filter must be string: $fromPrice-$toPrice
         */
        $filter = $request->getParam($this->getRequestVar());
        if (!$filter) {
            return $this;
        }

        //validate filter
        $filterParams = explode($separator, $filter);

        $priorFilters = array();
        foreach ($filterParams as $filterParam) {
            $filter = $this->_validateFilter($filterParam);
            if (!$filter) {
                break;
            }

            list($from, $to) = $filter;
            $this->setInterval(array($from, $to));
            $priorFilters[] = $filter;
            $this->_filterValues[] = $from;

            $this->getLayer()->getState()->addFilter($this->_createItem(
                $this->_renderRangeLabel(empty($from) ? 0 : $from, $to),
                $filter
            ));
        }

        $this->setPriorIntervals($priorFilters);
        $this->_applyPriceRange();

        return $this;
    }



    /**
     * Get filter value for reset current filter state
     *
     * @param string $filterValue Filter value
     * @return null|string
     */
    public function getResetFilterValue($filterValue)
    {
        $separator = Mage::helper('oggetto_filter/data')->getSeparator();

        if (is_array($filterValue)) {
            $currentFromPrice = $filterValue[0];
        } else {
            $currentFromPrice = explode('-', $filterValue)[0];
        }

        $priorIntervals = $this->getPriorIntervals();
        $value = array();
        if ($priorIntervals) {
            foreach ($priorIntervals as $priorInterval) {
                if ($priorInterval[0] !== $currentFromPrice) {
                    $value[] = implode('-', $priorInterval);
                }
            }
            return !empty($params) ? implode($separator, $value) : null;
        }
        return parent::getResetValue();
    }
}
