<?php

/**
 * @category    Inchoo
 * @package     Inchoo_UrlRewriteImporter
 * @author      Branko Ajzele <ajzele@gmail.com>
 * @copyright   Copyright (c) Inchoo
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Inchoo_UrlRewriteImporter_Adminhtml_Inchoo_UrlRewriteImporter_ImportController extends Mage_Adminhtml_Controller_Action {

    protected $_columns     = array();
    protected $_timestamp   = 0;
    protected $_currentId   = 0;

    protected function _getSession() {
        return Mage::getSingleton('adminhtml/session');
    }

    private function _allowedType($type) {
        $mimes = array(
            'text/csv',
            'text/plain',
            'application/csv',
            'text/comma-separated-values',
            'application/excel',
            'application/vnd.ms-excel',
            'application/vnd.msexcel',
            'text/anytext',
            'application/octet-stream',
            'application/txt',
        );

        if (in_array($type, $mimes)) {
            return true;
        }

        return false;
    }

    /**
     * Get a column index by field name.
     * 
     * @param string $column The column field name.
     * 
     * @return int|null
     */
    protected function _getColumnIndex($column)
    {
        if (isset($this->_columns[$column])) {
            return $this->_columns[$column];
        }

        return null;
    }

    /**
     * Transform the row for ease of import.
     * 
     * @param array $row The import row.
     * 
     * @return Varien_Object
     */
    protected function _prepareRow(array $row)
    {
        // User-supplied id_path, options will override CSV values
        $idPath     = $this->getRequest()->getParam('id_path_pattern');
        $options    = $this->getRequest()->getParam('options');
        $newRow     = array();
        // Merge column map with default placeholders
        $columns    = array_merge(
            array(
                'store_id'      => -1,
                'id_path'       => -1,
                'request_path'  => -1,
                'target_path'   => -1,
                'options'       => -1,
            ),
            $this->_columns
        );

        foreach ($columns as $field => $index) {
            $value = isset($row[$index]) ? $row[$index] : null;

            if ($field == 'id_path') {
                if ($idPath || is_null($value)) {
                    $value = $idPath ? $idPath : 'custom/{time}/{id}';
                }

                $value = str_replace('{time}', $this->_timestamp, $value);
                $value = str_replace('{id}', $this->_currentId, $value);
            }

            if ($field == 'options') {
                if ($options || is_null($value)) {
                    $value = $options ? $options : '';
                }
            }

            $newRow[$field] = $value;
        }

        return new Varien_Object($newRow);
    }

    /**
     * Set the column/field map.
     * 
     * @param array $columns An array of numerically-indexed columns.
     *
     * @return Inchoo_UrlRewriteImporter_Adminhtml_Inchoo_UrlRewriteImporter_ImportController
     */
    protected function _setColumns(array $columns = array())
    {
        $this->_columns = array_flip($columns);

        return $this;
    }

    public function saveAction() {
        if ($this->getRequest()->isPost()) {

            $filename = $_FILES['file']['tmp_name'];

            if (!file_exists($filename)) {
                $this->_getSession()->addError('Unable to upload the file!');
                $this->_redirectReferer();
                return;
            }

            if ($this->_allowedType($_FILES['file']['type']) == false) {
                $this->_getSession()->addError('Sorry, mime type not allowed!');
                $this->_redirectReferer();
                return;
            }

            $this->_currentId   = 1;
            $this->_timestamp   = time();

            $length     = $this->getRequest()->getParam('length', 0);
            $delimiter  = $this->getRequest()->getParam('delimiter', ',');
            $enclosure  = $this->getRequest()->getParam('enclosure', '"');
            $escape     = $this->getRequest()->getParam('escape', '\\');
            $skipline   = $this->getRequest()->getParam('skipline', false);
            $stores     = array_filter( explode(',', ( $this->getRequest()->getParam('store_id') )) );
            $columns    = explode(',', ( $this->getRequest()->getParam('fields') ));

            if (empty($stores)) {
                $stores = array(0);
            }

            if (empty($columns)) {
                $columns = array('store_id', 'id_path', 'request_path', 'target_path', 'options');
            }

            $this->_setColumns($columns);

            $total = 0;
            $totalSuccess = 0;
            $logException = '';

            if (($fp = fopen($filename, 'r'))) {
                while (($line = fgetcsv($fp, $length, $delimiter, $enclosure, $escape))) {

                    $total++;

                    if ($skipline && ($total == 1)) {
                        continue;
                    }

                    $row = $this->_prepareRow($line);

                    foreach ($stores as $store) {
                        $rewrite = Mage::getModel('core/url_rewrite');

                        $rewrite->setIdPath($row->getIdPath())
                                ->setDescription('URL rewrite import')
                                ->setIsSystem(0)
                                ->setTargetPath($row->getTargetPath())
                                ->setOptions($row->getOptions())
                                ->setRequestPath($row->getRequestPath())
                                ->setStoreId($store);

                        try {
                            $rewrite->save();
                            $totalSuccess++;
                            $this->_currentId++;
                        } catch (Exception $e) {
                            $logException = $e->getMessage();
                            Mage::logException($e);
                        }

                    }
                }
                fclose($fp);
                unlink($filename);

                if ($total === $totalSuccess) {
                    $this->_getSession()->addSuccess(sprintf('All %s URL rewrites have been successfully imported.', $total));
                } elseif ($totalSuccess == 0) {
                    $this->_getSession()->addError('No URL rewrites have been imported.');
                    if (!empty($logException)) {
                        $this->_getSession()->addError(sprintf('Last logged exception: %s', $logException));
                    }
                    $this->_redirectReferer();
                    return;
                } else {
                    $this->_getSession()->addNotice(sprintf('%s URL rewrites have been imported.', $total - $totalSuccess));
                    if (!empty($logException)) {
                        $this->_getSession()->addError(sprintf('Last logged exception: %s', $logException));
                    }
                }
            }
        }

        $this->_redirect('*/urlrewrite/index');
        
        return;
    }

    public function editAction() {
        $this->loadLayout();

        $this->_addContent($this->getLayout()->createBlock('inchoo_urlrewriteimporter/adminhtml_UrlRewriteImporter_edit'));
        $this->_addLeft($this->getLayout()->createBlock('inchoo_urlrewriteimporter/adminhtml_UrlRewriteImporter_edit_tabs'));

        $this->_setActiveMenu('catalog/urlrewrite');

        $this->renderLayout();
    }

    public function newAction() {
        $this->_forward('edit');
    }

}
