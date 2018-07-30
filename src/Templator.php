<?php
/**
 * Author: Andrey Morozov
 * Date: 21.07.2016
 */

namespace XLSXTemplate;

use \PHPExcel\IOFactory;
use \PHPExcel\Worksheet;

class Templator
{
    /**
     * @var string
     */
    private $templateFile;

    /**
     * @var string
     */
    private $templateFileType;

    /**
     * @var string
     */
    private $outputDir;

    /**
     * @var string
     */
    private $outputFileName;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var \PHPExcel
     */
    private $objPHPExcel;

    /**
     * @var bool
     */
    private $needsIgnoreEmpty = true;

    /**
     * @var string
     */
    private $limitColumnLetter;

    public function __construct($templateFile, $outputDir = null, $outputFileName = null)
    {
        if (!file_exists($templateFile) || !is_readable($templateFile)) {
            throw new \InvalidArgumentException('Template file "' . $templateFile . '" is not readable.');
        }
        if (isset($outputDir) && (!is_dir($outputDir) || !is_writable($outputDir))) {
            throw new \InvalidArgumentException('Output dirirectory "' . $outputDir . '" not writable.');
        }
        $this->templateFile = $templateFile;
        $this->outputDir = $outputDir;
        $this->outputFileName = $outputFileName;
    }

    public function loadTemplate()
    {
        try {
            $this->templateFileType = IOFactory::identify($this->templateFile);
            $objReader = IOFactory::createReader($this->templateFileType);
            $this->objPHPExcel = $objReader->load($this->templateFile);
        } catch(\Exception $e) {
            new \Exception('Error loading file "'.pathinfo($this->templateFile, PATHINFO_BASENAME).'": '.$e->getMessage());
        }
    }

    /**
     * @param Settings|null $settings
     * @param int|null $sheetIndex
     * @throws \Exception
     */
    public function render(Settings $settings = null, $sheetIndex = 1)
    {
        if ($settings) {
            $this->setSettings($settings);
        }
        if (!$this->settings) {
            throw new \Exception('Template settings are not set.');
        }
        if (!$this->objPHPExcel) {
            $this->loadTemplate();
        }

        /** @var Worksheet $worksheet */
        $worksheet = $this->objPHPExcel->getSheet(--$sheetIndex);

        /** @var Worksheet_RowIterator $rowIterator */
        $rowIterator =  $worksheet->getRowIterator();

        foreach ($rowIterator as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells($this->needsIgnoreEmpty);

            /** @var \Cell $cell */
            foreach ($cellIterator as $cell) {
                if ($this->limitColumnLetter && $cell->getColumn() === $this->limitColumnLetter) {
                    break;
                }
                $pCoordinate = $cell->getColumn().$cell->getRow();
                $value = $cell->getValue();

                if ($this->isStartLoop($value)) {
                    $worksheet->setCellValue($pCoordinate, '');
                    $this->replaceRowsСontentInLoop($rowIterator, $worksheet, $value);
                    $worksheet->removeRow($row->getRowIndex(), 1);
                    $rowIterator->resetEnd();

                    break;
                }

                if ($this->isTemplateCell($value)) {
                    $this->replaceСontent($worksheet, $pCoordinate, $value);
                }
            }
        }
    }

    /**
     * Save current file
     *
     * @param string|null $outputFileName
     *
     * @throws \Reader_Exception
     * @throws \Writer_Exception
     */
    public function save($outputFileName = null)
    {
        if (isset($outputFileName)) {
            $this->outputFileName = $outputFileName;
        }

        if (!isset($this->outputFileName)) {
            throw new \InvalidArgumentException('Output filename is not specified.');
        }

        $objWriter = IOFactory::createWriter($this->objPHPExcel, $this->templateFileType);
        $objWriter->save($this->outputDir.$this->outputFileName);
    }

    /**
     * Send current file to browser
     *
     * @param string|null $outputFileName
     *
     * @throws \PHPExcel\Writer\Exception
     */
    public function output($outputFileName = null)
    {
        if (isset($outputFileName)) {
            $this->outputFileName = $outputFileName;
        }

        if (!isset($this->outputFileName)) {
            throw new \InvalidArgumentException('Output filename is not specified.');
        }

        ob_get_clean();

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment;filename="'.rawurlencode($this->outputFileName).'"');
        header('Cache-Control: max-age=0');
        $objWriter = IOFactory::createWriter($this->objPHPExcel, $this->templateFileType);
        $objWriter->save('php://output');
    }

    /**
     * @param Worksheet_RowIterator $rowIterator
     * @param Worksheet $worksheet
     * @param string $cellValue
     */
    private function replaceRowsСontentInLoop($rowIterator, $worksheet, $cellValue)
    {
        $loopKey = $this->extractLoopKey($cellValue);

        $loopData = $this->settings->getLoopData($loopKey);
        if ($loopData === false) {
            return;
        }

        $rowIterator->next();
        $etalonRow = $rowIterator->current();
        $cellIterator = $etalonRow->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);

        $loopVariables = [];
        /** @var \Cell $cell */
        foreach ($cellIterator as $cell) {
            array_push($loopVariables, $cell->getValue());
        }

        if ($loopData->count() > 1) {
            $worksheet->insertNewRowBefore($rowIterator->key() + 1, $loopData->count() - 1);
        }

        $loopDataMap = $loopData->getMap();

        $rowNumber = 1;
        $needToMerge = [];

        foreach ($loopData->getSource() as $dataSource) {
            $row = $rowIterator->current();
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            $cellIndex = 0;

            /** @var \Cell $cell */
            foreach ($cellIterator as $cell) {
                $pCoordinate = $cell->getColumn().$cell->getRow();

                if ($rowNumber == 1) {
                    $range = $cell->getMergeRange();
                    if ($range && !isset($needToMerge[$range])) {
                        $needToMerge[$range] = null;
                    }
                }

                $this->replaceСontentInLoop(
                    $worksheet,
                    $pCoordinate,
                    $loopVariables[$cellIndex],
                    $dataSource,
                    $loopDataMap,
                    $rowNumber
                );
                $cellIndex++;
            }

            if ($rowNumber > 1 && $needToMerge) {
                foreach (array_keys($needToMerge) as $range) {
                    $worksheet->mergeCells(preg_replace('/\d+/', $row->getRowIndex(), $range));
                }
            }


            $rowNumber++;
            $rowIterator->next();
        }

        for ($i = 0; $i <= $rowNumber; $i++) {
            $rowIterator->prev();
        }
    }

    /**
     * @param Worksheet $worksheet
     * @param string $pCoordinate
     * @param string $cellValue
     * @param $dataSource
     * @param array $loopDataMap
     * @param int $rowNumber
     */
    private function replaceСontentInLoop($worksheet, $pCoordinate, $cellValue, $dataSource, $loopDataMap, $rowNumber)
    {
        if ($this->isTemplateCell($cellValue)) {
            $templateKey = $this->extractTemplateKey($cellValue);
            if ($templateKey === 'ROW_NUMBER') {
                $worksheet->setCellValue($pCoordinate, $rowNumber);
            } elseif (in_array($templateKey, $loopDataMap)) {
                $sourceKey = array_search($templateKey, $loopDataMap);
                $value = '';
                if (is_array($dataSource) && isset($dataSource[$sourceKey])) {
                    $value = $dataSource[$sourceKey];
                } elseif (is_object($dataSource) && isset($loopDataMap[$sourceKey])) {
                    $attributeName = $loopDataMap[$sourceKey];
                    $value = $dataSource->$attributeName;
                }
                $worksheet->setCellValue($pCoordinate, $value);
            }
        } elseif ($cellValue) {
            $worksheet->setCellValue($pCoordinate, $cellValue);
        }
    }

    /**
     * @param Worksheet $worksheet
     * @param string $pCoordinate
     * @param string $cellValue
     */
    private function replaceСontent(Worksheet $worksheet, $pCoordinate, $cellValue)
    {
        $templateKey = $this->extractTemplateKey($cellValue);
        $worksheet->setCellValue($pCoordinate, $this->settings->getValue($templateKey));
    }

    /**
     * @param string $cellValue
     * @return string
     */
    private function extractLoopKey($cellValue)
    {
        preg_match('/^\%LOOP (?<key>[\d\w]+)\%$/', $cellValue, $matches);

        return $matches['key'];
    }

    /**
     * @param string $cellValue
     * @return string
     */
    private function extractTemplateKey($cellValue)
    {
        return trim($cellValue, '%');
    }

    /**
     * Set template settings.
     *
     * @param Settings $settings
     */
    public function setSettings(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param boolean $value
     */
    public function setNeedsIgnoreEmpty($value)
    {
        $this->needsIgnoreEmpty = (boolean) $value;
    }

    /**
     * @param string $value
     */
    public function setLimitColumnLetter($value)
    {
        if (preg_match('/^[A-Z]{1,2}$/', $value)) {
            $this->limitColumnLetter = $value;
        }
    }

    /**
     * @param string $cellValue
     * @return boolean
     */
    private function isTemplateCell($cellValue)
    {
        return (boolean) preg_match('/^\%[\d\w]+\%$/', $cellValue);
    }

    /**
     * @param string $cellValue
     * @return boolean
     */
    private function isStartLoop($cellValue)
    {
        return (boolean) preg_match('/^\%LOOP [\d\w]+\%$/', $cellValue);
    }
}