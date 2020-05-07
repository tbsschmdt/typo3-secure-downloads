<?php
declare(strict_types = 1);
namespace Leuchtfeuer\SecureDownloads\Domain\Repository;

/***
 *
 * This file is part of the "Secure Downloads" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2019 Florian Wessels <f.wessels@Leuchtfeuer.com>, Leuchtfeuer Digital Marketing
 *
 ***/

use Leuchtfeuer\SecureDownloads\Domain\Model\Log;
use Leuchtfeuer\SecureDownloads\Domain\Transfer\Filter;
use Leuchtfeuer\SecureDownloads\Domain\Transfer\Token\AbstractToken;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class LogRepository extends Repository
{
    protected $defaultOrderings = [
        'tstamp' => QueryInterface::ORDER_DESCENDING,
    ];

    public function initializeObject()
    {
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = $this->objectManager->get(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $querySettings->setRespectSysLanguage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    public function findByFilter(?Filter $filter): QueryResultInterface
    {
        $query = $this->createQuery();

        if ($filter instanceof Filter) {
            try {
                $this->applyFilter($query, $filter);
            } catch (InvalidQueryException $exception) {
                // Do nothing for now.
            }
        }

        return $query->execute();
    }

    /**
     * @throws InvalidQueryException
     */
    protected function applyFilter(QueryInterface &$query, Filter $filter): void
    {
        $constraints = [];

        // FileType
        if ($filter->getFileType() !== '' && $filter->getFileType() !== '0') {
            $constraints[] = $query->equals('mediaType', $filter->getFileType());
        }

        // User Type
        if ($filter->getUserType() != 0) {
            $userQuery = $query->equals('user', null);

            if ($filter->getUserType() === Filter::USER_TYPE_LOGGED_ON) {
                $constraints[] = $query->logicalNot($userQuery);
            }
            if ($filter->getUserType() === Filter::USER_TYPE_LOGGED_OFF) {
                $constraints[] = $userQuery;
            }
        }

        // User
        if ($filter->getFeUserId() !== 0) {
            $constraints[] = $query->equals('user', $filter->getFeUserId());
        }

        // Timeframe
        if ($filter->getFrom() !== '' && $filter->getFrom() !== null) {
            $constraints[] = $query->greaterThanOrEqual('tstamp', $filter->getFrom());
        }

        if ($filter->getTill() !== '' && $filter->getTill() !== null) {
            $constraints[] = $query->lessThanOrEqual('tstamp', $filter->getTill());
        }

        // Page
        if ($filter->getPageId() !== 0) {
            $constraints[] = $query->equals('page', $filter->getPageId());
        }

        if (count($constraints) > 0) {
            $query->matching($query->logicalAnd($constraints));
        }
    }

    public function logDownload(AbstractToken $token, $fileSize, $mimeType, $user)
    {
        $pathInfo = pathinfo($token->getFile());

        $log = new Log();
        $log->setFileSize($fileSize);
        $log->setFilePath($pathInfo['dirname'] . '/' . $pathInfo['filename']);
        $log->setFileType($pathInfo['extension']);
        $log->setFileName($pathInfo['filename']);
        $log->setMediaType($mimeType);
        $log->setUser($user);
        $log->setPage($token->getPage());

        $fileObject = GeneralUtility::makeInstance(ResourceFactory::class)->retrieveFileOrFolderObject($token->getFile());

        if ($fileObject) {
            $log->setFileId((string)$fileObject->getUid());
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_securedownloads_domain_model_log');
        $queryBuilder->insert('tx_securedownloads_domain_model_log')->values($log->toArray())->execute();
    }
}
