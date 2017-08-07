<?php
/*
 * Copyright (C) 2015-2017, Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace Fossology\ReportImport;

use Fossology\Lib\Data\License;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\ReportImport\ReportImportHelper;
use Fossology\Lib\Data\Clearing\ClearingEventTypes;
use Fossology\Lib\Data\DecisionScopes;
use Fossology\Lib\Data\DecisionTypes;

require_once 'ReportImportConfiguration.php';

class ReportImportSink
{

  /** @var LicenseDao */
  private $licenseDao;
  /** @var ClearingDao */
  private $clearingDao;
  /** @var DbManager */
  protected $dbManager;

  /** @var int */
  protected $agent_pk = -1;
  /** @var int */
  protected $groupId = -1;
  /** @var int */
  protected $userId = -1;
  /** @var int */
  protected $jobId = -1;

  /** @var ReportImportConfiguration */
  protected $configuration;

  /**
   * ReportImportSink constructor.
   * @param $agent_pk
   * @param $licenseDao
   * @param $clearingDao
   * @param $dbManager
   * @param $groupId
   * @param $userId
   * @param $jobId
   * @param $configuration
   */
  function __construct($agent_pk, $licenseDao, $clearingDao, $dbManager, $groupId, $userId, $jobId, $configuration)
  {
    $this->clearingDao = $clearingDao;
    $this->licenseDao = $licenseDao;
    $this->dbManager = $dbManager;
    $this->agent_pk = $agent_pk;
    $this->groupId = $groupId;
    $this->userId = $userId;
    $this->jobId = $jobId;

    $this->configuration = $configuration;
  }

  /**
   * @param ReportImportData $data
   */
  public function handleData($data)
  {
    $pfiles = $data->getPfiles();
    if(sizeof($pfiles) === 0)
    {
      return;
    }

    if($this->configuration->isCreateLicensesInfosAsFindings() ||
       $this->configuration->isCreateConcludedLicensesAsFindings() ||
       $this->configuration->isCreateConcludedLicensesAsConclusions())
    {
      $licenseInfosInFile = $data->getLicenseInfosInFile();
      $licensesConcluded = $data->getLicensesConcluded();

      $licensePKsInFile = array();
      foreach($licenseInfosInFile as $dataItem)
      {
        if (strcasecmp($dataItem->getLicenseId(), "noassertion") == 0)
        {
          continue;
        }
        $licenseId = $this->getIdForDataItemOrCreateLicense($dataItem, $this->groupId);
        $licensePKsInFile[] = $licenseId;
      }

      $licensePKsConcluded = array();
      foreach ($licensesConcluded as $dataItem)
      {
        if (strcasecmp($dataItem->getLicenseId(), "noassertion") == 0)
        {
          continue;
        }
        $licenseId = $this->getIdForDataItemOrCreateLicense($dataItem, $this->groupId);
      $licensePKsConcluded[$licenseId] = $dataItem->getCustomText();
      }

      $this->insertLicenseInformationToDB($licensePKsInFile, $licensePKsConcluded, $pfiles);
    }

    if($this->configuration->isAddCopyrightInformation())
    {
      $this->insertFoundCopyrightTextsToDB($data->getCopyrightTexts(),
        $data->getPfiles());
    }
  }

  /**
   * @param ReportImportDataItem $dataItem
   * @param $groupId
   * @return int
   * @throws \Exception
   */
  public function getIdForDataItemOrCreateLicense($dataItem , $groupId)
  {
    $licenseShortName = $dataItem->getLicenseId();
    $license = $this->licenseDao->getLicenseByShortName($licenseShortName, $groupId);
    if ($license !== null)
    {
      return $license->getId();
    }
    elseif (! $this->licenseDao->isNewLicense($licenseShortName, $groupId))
    {
      throw new \Exception('shortname already in use');
    }
    elseif ($dataItem->isSetLicenseCandidate())
    {
      $licenseCandidate = $dataItem->getLicenseCandidate();
      echo "INFO: No license with shortname=\"$licenseShortName\" found ... ";
      if($this->configuration->isCreateLicensesAsCandidate())
      {
        echo "Creating it as license candidate ...\n";
        $licenseId = $this->licenseDao->insertUploadLicense($licenseShortName, $licenseCandidate->getText(), $groupId);
        $this->licenseDao->updateCandidate(
          $licenseId,
          $licenseCandidate->getShortName(),
          $licenseCandidate->getFullName(),
          $licenseCandidate->getText(),
          $licenseCandidate->getUrl(),
          "Created for ReportImport with jobId=[".$this->jobId."]",
          false,
          0);
        return $licenseId;
      }
      else
      {
        echo "creating it as license ...\n";
        $licenseText = trim($licenseCandidate->getText());
        return $this->dbManager->getSingleRow(
          "INSERT INTO license_ref (rf_shortname, rf_text, rf_detector_type, rf_spdx_compatible) VALUES ($1, $2, 2, $3) RETURNING rf_pk",
          array($licenseCandidate->getShortName(), $licenseText, $licenseCandidate->getSpdxCompatible()),
          __METHOD__.".addLicense" )[0];
      }
    }
    return -1;
  }

  /**
   * @param array $licensePKsInFile
   * @param array $licensePKsConcluded
   * @param array $pfiles
   */
  private function insertLicenseInformationToDB($licensePKsInFile, $licensePKsConcluded, $pfiles)
  {
    $this->saveAsLicenseFindingToDB($licensePKsInFile, $pfiles);

    if($this->configuration->isCreateConcludedLicensesAsFindings())
    {
      $this->saveAsLicenseFindingToDB($licensePKsConcluded, $pfiles);
    }

    if($this->configuration->isCreateConcludedLicensesAsConclusions())
    {
      $removeLicenseIds = array(); // TODO
      foreach ($licensePKsInFile as $licenseId)
      {
        if(! array_key_exists($licenseId,$licensePKsConcluded))
        {
          $removeLicenseIds[] = $licenseId;
        }
      }
      $this->saveAsDecisionToDB($licensePKsConcluded, $removeLicenseIds, $pfiles);
    }
  }

  /**
   * @param array $addLicenseIds
   * @param array $removeLicenseIds
   * @param array $pfiles
   */
  private function saveAsDecisionToDB($addLicenseIds, $removeLicenseIds, $pfiles)
  {
    foreach ($pfiles as $pfile)
    {
      $eventIds = array();
      foreach ($addLicenseIds as $licenseId => $licenseText)
      {
        echo "add decision $licenseId to " . $pfile['uploadtree_pk'] . "\n";
        $eventIds[] = $this->clearingDao->insertClearingEvent(
          $pfile['uploadtree_pk'],
          $this->userId,
          $this->groupId,
          $licenseId,
          false,
          ClearingEventTypes::IMPORT,
          trim($licenseText),
          '', // comment
          $this->jobId);
      }
      foreach ($removeLicenseIds as $licenseId)
      {
        echo "remove decision $licenseId from " . $pfile['uploadtree_pk'] . "\n";
        $eventIds[] = $this->clearingDao->insertClearingEvent(
          $pfile['uploadtree_pk'],
          $this->userId,
          $this->groupId,
          $licenseId,
          true,
          ClearingEventTypes::IMPORT,
          $licenseText,
          '', // comment
          $this->jobId);
      }
      $this->clearingDao->createDecisionFromEvents(
        $pfile['uploadtree_pk'],
        $this->userId,
        $this->groupId,
        $this->configuration->getConcludeLicenseDecisionType(),
        DecisionScopes::ITEM,
        $eventIds);
    }
  }

  /**
   * @param array $licenseIds
   * @param array $pfiles
   */
  private function saveAsLicenseFindingToDB($licenseIds, $pfiles)
  {
    foreach ($pfiles as $pfile)
    {
      foreach($licenseIds as $licenseId)
      {
        $this->dbManager->getSingleRow(
          "INSERT INTO license_file (rf_fk, agent_fk, pfile_fk) VALUES ($1,$2,$3) RETURNING fl_pk",
          array($licenseId, $this->agent_pk, $pfile['pfile_pk']),
          __METHOD__."forReportImport");
      }
    }
  }

  public function insertFoundCopyrightTextsToDB($copyrightTexts, $entries)
  {
    foreach ($copyrightTexts as $copyrightText)
    {
      $this->insertFoundCopyrightTextToDB($copyrightText, $entries);
    }
  }

  public function insertFoundCopyrightTextToDB($copyrightText, $entries)
  {
    $copyrightLines = array_map("trim", explode("\n",$copyrightText));
    foreach ($copyrightLines as $copyrightLine)
    {
      if(empty($copyrightLine))
      {
        continue;
      }

      foreach ($entries as $entry)
      {
        $this->saveAsCopyrightFindingToDB(trim($copyrightLine), $entry['pfile_pk']);
      }
    }
  }

  private function saveAsCopyrightFindingToDB($content, $pfile_fk)
  {
    return $this->dbManager->getSingleRow(
      "insert into copyright(agent_fk, pfile_fk, content, hash, type) values($1,$2,$3,md5($3),$4) RETURNING ct_pk",
      array($this->agent_pk, $pfile_fk, $content, "statement"),
      __METHOD__."forReportImport");
  }
}
