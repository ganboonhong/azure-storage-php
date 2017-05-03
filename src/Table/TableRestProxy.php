<?php

/**
 * LICENSE: The MIT License (the "License")
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * https://github.com/azure/azure-storage-php/LICENSE
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * PHP version 5
 *
 * @category  Microsoft
 * @package   MicrosoftAzure\Storage\Table
 * @author    Azure Storage PHP SDK <dmsh@microsoft.com>
 * @copyright 2016 Microsoft Corporation
 * @license   https://github.com/azure/azure-storage-php/LICENSE
 * @link      https://github.com/azure/azure-storage-php
 */

namespace MicrosoftAzure\Storage\Table;

use MicrosoftAzure\Storage\Common\Internal\ServiceRestTrait;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use MicrosoftAzure\Storage\Common\Internal\Utilities;
use MicrosoftAzure\Storage\Common\Internal\Validate;
use MicrosoftAzure\Storage\Common\Internal\Http\HttpCallContext;
use MicrosoftAzure\Storage\Common\Internal\ServiceRestProxy;
use MicrosoftAzure\Storage\Common\LocationMode;
use MicrosoftAzure\Storage\Table\Internal\ITable;
use MicrosoftAzure\Storage\Table\Models\TableServiceOptions;
use MicrosoftAzure\Storage\Table\Models\EdmType;
use MicrosoftAzure\Storage\Table\Models\Filters;
use MicrosoftAzure\Storage\Table\Models\Filters\Filter;
use MicrosoftAzure\Storage\Table\Models\Filters\PropertyNameFilter;
use MicrosoftAzure\Storage\Table\Models\Filters\ConstantFilter;
use MicrosoftAzure\Storage\Table\Models\Filters\UnaryFilter;
use MicrosoftAzure\Storage\Table\Models\Filters\BinaryFilter;
use MicrosoftAzure\Storage\Table\Models\Filters\QueryStringFilter;
use MicrosoftAzure\Storage\Table\Models\GetTableResult;
use MicrosoftAzure\Storage\Table\Models\QueryTablesOptions;
use MicrosoftAzure\Storage\Table\Models\QueryTablesResult;
use MicrosoftAzure\Storage\Table\Models\InsertEntityResult;
use MicrosoftAzure\Storage\Table\Models\UpdateEntityResult;
use MicrosoftAzure\Storage\Table\Models\QueryEntitiesOptions;
use MicrosoftAzure\Storage\Table\Models\QueryEntitiesResult;
use MicrosoftAzure\Storage\Table\Models\DeleteEntityOptions;
use MicrosoftAzure\Storage\Table\Models\GetEntityResult;
use MicrosoftAzure\Storage\Table\Models\BatchOperationType;
use MicrosoftAzure\Storage\Table\Models\BatchOperationParameterName;
use MicrosoftAzure\Storage\Table\Models\BatchResult;
use MicrosoftAzure\Storage\Table\Models\TableACL;
use MicrosoftAzure\Storage\Common\Internal\Http\HttpFormatter;
use MicrosoftAzure\Storage\Table\Internal\IAtomReaderWriter;
use MicrosoftAzure\Storage\Table\Internal\IMimeReaderWriter;
use MicrosoftAzure\Storage\Common\Internal\Serialization\ISerializer;

/**
 * This class constructs HTTP requests and receive HTTP responses for table
 * service layer.
 *
 * @category  Microsoft
 * @package   MicrosoftAzure\Storage\Table
 * @author    Azure Storage PHP SDK <dmsh@microsoft.com>
 * @copyright 2016 Microsoft Corporation
 * @license   https://github.com/azure/azure-storage-php/LICENSE
 * @link      https://github.com/azure/azure-storage-php
 */
class TableRestProxy extends ServiceRestProxy implements ITable
{
    use ServiceRestTrait;

    /**
     * @var Internal\IAtomReaderWriter
     */
    private $_atomSerializer;

    /**
     *
     * @var Internal\IMimeReaderWriter
     */
    private $_mimeSerializer;

    /**
     * Creates contexts for batch operations.
     *
     * @param array $operations The batch operations array.
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function _createOperationsContexts(array $operations)
    {
        $contexts = array();

        foreach ($operations as $operation) {
            $context = null;
            $type    = $operation->getType();

            switch ($type) {
                case BatchOperationType::INSERT_ENTITY_OPERATION:
                case BatchOperationType::UPDATE_ENTITY_OPERATION:
                case BatchOperationType::MERGE_ENTITY_OPERATION:
                case BatchOperationType::INSERT_REPLACE_ENTITY_OPERATION:
                case BatchOperationType::INSERT_MERGE_ENTITY_OPERATION:
                    $table   = $operation->getParameter(
                        BatchOperationParameterName::BP_TABLE
                    );
                    $entity  = $operation->getParameter(
                        BatchOperationParameterName::BP_ENTITY
                    );
                    $context = $this->_getOperationContext($table, $entity, $type);
                    break;
    
                case BatchOperationType::DELETE_ENTITY_OPERATION:
                    $table        = $operation->getParameter(
                        BatchOperationParameterName::BP_TABLE
                    );
                    $partitionKey = $operation->getParameter(
                        BatchOperationParameterName::BP_PARTITION_KEY
                    );
                    $rowKey       = $operation->getParameter(
                        BatchOperationParameterName::BP_ROW_KEY
                    );
                    $etag         = $operation->getParameter(
                        BatchOperationParameterName::BP_ETAG
                    );
                    $options      = new DeleteEntityOptions();
                    $options->setETag($etag);
                    $context = $this->_constructDeleteEntityContext(
                        $table,
                        $partitionKey,
                        $rowKey,
                        $options
                    );
                    break;
    
                default:
                    throw new \InvalidArgumentException();
            }

            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * Creates operation context for the API.
     *
     * @param string        $table  The table name.
     * @param Models\Entity $entity The entity object.
     * @param string        $type   The API type.
     *
     * @return \MicrosoftAzure\Storage\Common\Internal\Http\HttpCallContext
     *
     * @throws \InvalidArgumentException
     */
    private function _getOperationContext($table, Models\Entity $entity, $type)
    {
        switch ($type) {
            case BatchOperationType::INSERT_ENTITY_OPERATION:
                return $this->_constructInsertEntityContext($table, $entity, null);
    
            case BatchOperationType::UPDATE_ENTITY_OPERATION:
                return $this->_constructPutOrMergeEntityContext(
                    $table,
                    $entity,
                    Resources::HTTP_PUT,
                    true,
                    null
                );
    
            case BatchOperationType::MERGE_ENTITY_OPERATION:
                return $this->_constructPutOrMergeEntityContext(
                    $table,
                    $entity,
                    Resources::HTTP_MERGE,
                    true,
                    null
                );
    
            case BatchOperationType::INSERT_REPLACE_ENTITY_OPERATION:
                return $this->_constructPutOrMergeEntityContext(
                    $table,
                    $entity,
                    Resources::HTTP_PUT,
                    false,
                    null
                );
    
            case BatchOperationType::INSERT_MERGE_ENTITY_OPERATION:
                return $this->_constructPutOrMergeEntityContext(
                    $table,
                    $entity,
                    Resources::HTTP_MERGE,
                    false,
                    null
                );
    
            default:
                throw new \InvalidArgumentException();
        }
    }

    /**
     * Creates MIME part body for batch API.
     *
     * @param array $operations The batch operations.
     * @param array $contexts   The contexts objects.
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    private function _createBatchRequestBody(array $operations, array $contexts)
    {
        $mimeBodyParts = array();
        $contentId     = 1;
        $count         = count($operations);

        Validate::isTrue(
            count($operations) == count($contexts),
            Resources::INVALID_OC_COUNT_MSG
        );

        for ($i = 0; $i < $count; $i++) {
            $operation = $operations[$i];
            $context   = $contexts[$i];
            $type      = $operation->getType();

            switch ($type) {
                case BatchOperationType::INSERT_ENTITY_OPERATION:
                case BatchOperationType::UPDATE_ENTITY_OPERATION:
                case BatchOperationType::MERGE_ENTITY_OPERATION:
                case BatchOperationType::INSERT_REPLACE_ENTITY_OPERATION:
                case BatchOperationType::INSERT_MERGE_ENTITY_OPERATION:
                    $contentType  = $context->getHeader(Resources::CONTENT_TYPE);
                    $body         = $context->getBody();
                    $contentType .= ';type=entry';
                    $context->addOptionalHeader(Resources::CONTENT_TYPE, $contentType);
                    // Use mb_strlen instead of strlen to get the length of the string
                    // in bytes instead of the length in chars.
                    $context->addOptionalHeader(
                        Resources::CONTENT_LENGTH,
                        strlen($body)
                    );
                    break;
    
                case BatchOperationType::DELETE_ENTITY_OPERATION:
                    break;
    
                default:
                    throw new \InvalidArgumentException();
            }

            $context->addOptionalHeader(Resources::CONTENT_ID, $contentId);
            $mimeBodyPart    = $context->__toString();
            $mimeBodyParts[] = $mimeBodyPart;
            $contentId++;
        }

        return $this->_mimeSerializer->encodeMimeMultipart($mimeBodyParts);
    }

    /**
     * Constructs HTTP call context for deleteEntity API.
     *
     * @param string                     $table        The name of the table.
     * @param string                     $partitionKey The entity partition key.
     * @param string                     $rowKey       The entity row key.
     * @param Models\DeleteEntityOptions $options      The optional parameters.
     *
     * @return HttpCallContext
     */
    private function _constructDeleteEntityContext(
        $table,
        $partitionKey,
        $rowKey,
        Models\DeleteEntityOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');
        Validate::isTrue(!is_null($partitionKey), Resources::NULL_TABLE_KEY_MSG);
        Validate::isTrue(!is_null($rowKey), Resources::NULL_TABLE_KEY_MSG);

        $method      = Resources::HTTP_DELETE;
        $headers     = array();
        $queryParams = array();
        $statusCode  = Resources::STATUS_NO_CONTENT;
        $path        = $this->_getEntityPath($table, $partitionKey, $rowKey);

        if (is_null($options)) {
            $options = new DeleteEntityOptions();
        }

        $etagObj = $options->getETag();
        $ETag    = !is_null($etagObj);
        $this->addOptionalHeader(
            $headers,
            Resources::IF_MATCH,
            $ETag ? $etagObj : Resources::ASTERISK
        );

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);

        $context = new HttpCallContext();
        $context->setHeaders($headers);
        $context->setMethod($method);
        $context->setPath($path);
        $context->setQueryParameters($queryParams);
        $context->addStatusCode($statusCode);
        $context->setBody('');
        $context->setServiceOptions($options);

        return $context;
    }

    /**
     * Constructs HTTP call context for updateEntity, mergeEntity,
     * insertOrReplaceEntity and insertOrMergeEntity.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The entity instance to use.
     * @param string                     $verb    The HTTP method.
     * @param boolean                    $useETag The flag to include etag or not.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return HttpCallContext
     */
    private function _constructPutOrMergeEntityContext(
        $table,
        Models\Entity $entity,
        $verb,
        $useETag,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');
        Validate::notNullOrEmpty($entity, 'entity');
        Validate::isTrue($entity->isValid($msg), $msg);

        $method       = $verb;
        $headers      = array();
        $queryParams  = array();
        $statusCode   = Resources::STATUS_NO_CONTENT;
        $partitionKey = $entity->getPartitionKey();
        $rowKey       = $entity->getRowKey();
        $path         = $this->_getEntityPath($table, $partitionKey, $rowKey);
        $body         = $this->_atomSerializer->getEntity($entity);

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        if ($useETag) {
            $etag         = $entity->getETag();
            $ifMatchValue = is_null($etag) ? Resources::ASTERISK : $etag;

            $this->addOptionalHeader($headers, Resources::IF_MATCH, $ifMatchValue);
        }

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);
        $context = new HttpCallContext();
        $context->setBody($body);
        $context->setHeaders($headers);
        $context->setMethod($method);
        $context->setPath($path);
        $context->setQueryParameters($queryParams);
        $context->addStatusCode($statusCode);
        $context->setServiceOptions($options);

        return $context;
    }

    /**
     * Constructs HTTP call context for insertEntity API.
     *
     * @param string                     $table   The name of the table.
     * @param Models\Entity              $entity  The table entity.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return HttpCallContext
     */
    private function _constructInsertEntityContext(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');
        Validate::notNullOrEmpty($entity, 'entity');
        Validate::isTrue($entity->isValid($msg), $msg);

        $method      = Resources::HTTP_POST;
        $context     = new HttpCallContext();
        $headers     = array();
        $queryParams = array();
        $statusCode  = Resources::STATUS_CREATED;
        $path        = $table;
        $body        = $this->_atomSerializer->getEntity($entity);

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);
        $context->setBody($body);
        $context->setHeaders($headers);
        $context->setMethod($method);
        $context->setPath($path);
        $context->setQueryParameters($queryParams);
        $context->addStatusCode($statusCode);
        $context->setServiceOptions($options);

        return $context;
    }

    /**
     * Constructs URI path for entity.
     *
     * @param string $table        The table name.
     * @param string $partitionKey The entity's partition key.
     * @param string $rowKey       The entity's row key.
     *
     * @return string
     */
    private function _getEntityPath($table, $partitionKey, $rowKey)
    {
        $encodedPK = $this->_encodeODataUriValue($partitionKey);
        $encodedRK = $this->_encodeODataUriValue($rowKey);

        return "$table(PartitionKey='$encodedPK',RowKey='$encodedRK')";
    }

    /**
     * Creates a promie that does the actual work for update and merge entity
     * APIs.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The entity instance to use.
     * @param string                     $verb    The HTTP method.
     * @param boolean                    $useETag The flag to include etag or not.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function _putOrMergeEntityAsyncImpl(
        $table,
        Models\Entity $entity,
        $verb,
        $useETag,
        Models\TableServiceOptions $options = null
    ) {
        $context = $this->_constructPutOrMergeEntityContext(
            $table,
            $entity,
            $verb,
            $useETag,
            $options
        );

        return $this->sendContextAsync($context)->then(function ($response) {
            return UpdateEntityResult::create(
                HttpFormatter::formatHeaders($response->getHeaders())
            );
        }, null);
    }

    /**
     * Builds filter expression
     *
     * @param Filter $filter The filter object
     *
     * @return string
     */
    private function _buildFilterExpression(Filter $filter)
    {
        $e = Resources::EMPTY_STRING;
        $this->_buildFilterExpressionRec($filter, $e);

        return $e;
    }

    /**
     * Builds filter expression
     *
     * @param Filter $filter The filter object
     * @param string &$e     The filter expression
     *
     * @return string
     */
    private function _buildFilterExpressionRec(Filter $filter, &$e)
    {
        if (is_null($filter)) {
            return;
        }

        if ($filter instanceof PropertyNameFilter) {
            $e .= $filter->getPropertyName();
        } elseif ($filter instanceof ConstantFilter) {
            $value = $filter->getValue();
            // If the value is null we just append null regardless of the edmType.
            if (is_null($value)) {
                $e .= 'null';
            } else {
                $type = $filter->getEdmType();
                $e   .= EdmType::serializeQueryValue($type, $value);
            }
        } elseif ($filter instanceof UnaryFilter) {
            $e .= $filter->getOperator();
            $e .= '(';
            $this->_buildFilterExpressionRec($filter->getOperand(), $e);
            $e .= ')';
        } elseif ($filter instanceof Filters\BinaryFilter) {
            $e .= '(';
            $this->_buildFilterExpressionRec($filter->getLeft(), $e);
            $e .= ' ';
            $e .= $filter->getOperator();
            $e .= ' ';
            $this->_buildFilterExpressionRec($filter->getRight(), $e);
            $e .= ')';
        } elseif ($filter instanceof QueryStringFilter) {
            $e .= $filter->getQueryString();
        }

        return $e;
    }

    /**
     * Adds query object to the query parameter array
     *
     * @param array        $queryParam The URI query parameters
     * @param Models\Query $query      The query object
     *
     * @return array
     */
    private function _addOptionalQuery(array $queryParam, Models\Query $query)
    {
        if (!is_null($query)) {
            $selectedFields = $query->getSelectFields();
            if (!empty($selectedFields)) {
                $final = $this->_encodeODataUriValues($selectedFields);

                $this->addOptionalQueryParam(
                    $queryParam,
                    Resources::QP_SELECT,
                    implode(',', $final)
                );
            }

            if (!is_null($query->getTop())) {
                $final = strval($this->_encodeODataUriValue($query->getTop()));

                $this->addOptionalQueryParam(
                    $queryParam,
                    Resources::QP_TOP,
                    $final
                );
            }

            if (!is_null($query->getFilter())) {
                $final = $this->_buildFilterExpression($query->getFilter());
                $this->addOptionalQueryParam(
                    $queryParam,
                    Resources::QP_FILTER,
                    $final
                );
            }
        }

        return $queryParam;
    }

    /**
     * Encodes OData URI values
     *
     * @param array $values The OData URL values
     *
     * @return array
     */
    private function _encodeODataUriValues(array $values)
    {
        $list = array();

        foreach ($values as $value) {
            $list[] = $this->_encodeODataUriValue($value);
        }

        return $list;
    }

    /**
     * Encodes OData URI value
     *
     * @param string $value The OData URL value
     *
     * @return string
     */
    private function _encodeODataUriValue($value)
    {
        // Replace each single quote (') with double single quotes ('') not doudle
        // quotes (")
        $value = str_replace('\'', '\'\'', $value);

        // Encode the special URL characters
        $value = rawurlencode($value);

        return $value;
    }

    /**
     * Initializes new TableRestProxy object.
     *
     * @param string            $primaryUri     The storage account primary uri.
     * @param string            $secondaryUri   The storage account secondary uri.
     * @param IAtomReaderWriter $atomSerializer The atom serializer.
     * @param IMimeReaderWriter $mimeSerializer The MIME serializer.
     * @param ISerializer       $dataSerializer The data serializer.
     * @param array             $options        Array of options to pass to the service
     */
    public function __construct(
        $primaryUri,
        $secondaryUri,
        IAtomReaderWriter $atomSerializer,
        IMimeReaderWriter $mimeSerializer,
        ISerializer $dataSerializer,
        array $options = []
    ) {
        parent::__construct(
            $primaryUri,
            $secondaryUri,
            Resources::EMPTY_STRING,
            $dataSerializer,
            $options
        );
        $this->_atomSerializer = $atomSerializer;
        $this->_mimeSerializer = $mimeSerializer;
    }

    /**
     * Quries tables in the given storage account.
     *
     * @param Models\QueryTablesOptions|string|Models\Filters\Filter $options Could be
     * optional parameters, table prefix or filter to apply.
     *
     * @return Models\QueryTablesResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179405.aspx
     */
    public function queryTables($options = null)
    {
        return $this->queryTablesAsync($options)->wait();
    }

    /**
     * Creates promise to query the tables in the given storage account.
     *
     * @param Models\QueryTablesOptions|string|Models\Filters\Filter $options Could be
     * optional parameters, table prefix or filter to apply.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179405.aspx
     */
    public function queryTablesAsync($options = null)
    {
        $method      = Resources::HTTP_GET;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $path        = 'Tables';

        if (is_null($options)) {
            $options = new QueryTablesOptions();
        } elseif (is_string($options)) {
            $prefix  = $options;
            $options = new QueryTablesOptions();
            $options->setPrefix($prefix);
        } elseif ($options instanceof Filter) {
            $filter  = $options;
            $options = new QueryTablesOptions();
            $options->setFilter($filter);
        }

        $query   = $options->getQuery();
        $next    = $options->getNextTableName();
        $prefix  = $options->getPrefix();

        if (!empty($prefix)) {
            // Append Max char to end '{' is 1 + 'z' in AsciiTable ==> upperBound
            // is prefix + '{'
            $prefixFilter = Filter::applyAnd(
                Filter::applyGe(
                    Filter::applyPropertyName('TableName'),
                    Filter::applyConstant($prefix, EdmType::STRING)
                ),
                Filter::applyLe(
                    Filter::applyPropertyName('TableName'),
                    Filter::applyConstant($prefix . '{', EdmType::STRING)
                )
            );

            if (is_null($query)) {
                $query = new Models\Query();
            }

            if (is_null($query->getFilter())) {
                // use the prefix filter if the query filter is null
                $query->setFilter($prefixFilter);
            } else {
                // combine and use the prefix filter if the query filter exists
                $combinedFilter = Filter::applyAnd(
                    $query->getFilter(),
                    $prefixFilter
                );
                $query->setFilter($combinedFilter);
            }
        }

        $queryParams = $this->_addOptionalQuery($queryParams, $query);

        $this->addOptionalQueryParam(
            $queryParams,
            Resources::QP_NEXT_TABLE_NAME,
            $next
        );

        // One can specify the NextTableName option to get table entities starting
        // from the specified name. However, there appears to be an issue in the
        // Azure Table service where this does not engage on the server unless
        // $filter appears in the URL. The current behavior is to just ignore the
        // NextTableName options, which is not expected or easily detectable.
        if (array_key_exists(Resources::QP_NEXT_TABLE_NAME, $queryParams)
            && !array_key_exists(Resources::QP_FILTER, $queryParams)
        ) {
            $queryParams[Resources::QP_FILTER] = Resources::EMPTY_STRING;
        }

        $atomSerializer = $this->_atomSerializer;

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_OK,
            Resources::EMPTY_STRING,
            $options
        )->then(function ($response) use ($atomSerializer) {
            $tables = $atomSerializer->parseTableEntries($response->getBody());
            return QueryTablesResult::create(
                HttpFormatter::formatHeaders($response->getHeaders()),
                $tables
            );
        }, null);
    }

    /**
     * Creates new table in the storage account
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return void
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd135729.aspx
     */
    public function createTable($table, Models\TableServiceOptions $options = null)
    {
        $this->createTableAsync($table, $options)->wait();
    }

    /**
     * Creates promise to create new table in the storage account
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd135729.aspx
     */
    public function createTableAsync(
        $table,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');

        $method      = Resources::HTTP_POST;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $path        = 'Tables';
        $body        = $this->_atomSerializer->getTable($table);

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_CREATED,
            $body,
            $options
        );
    }

    /**
     * Gets the table.
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return Models\GetTableResult
     */
    public function getTable($table, Models\TableServiceOptions $options = null)
    {
        return $this->getTableAsync($table, $options)->wait();
    }

    /**
     * Creates the promise to get the table.
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function getTableAsync(
        $table,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');

        $method      = Resources::HTTP_GET;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $path        = "Tables('$table')";

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        $atomSerializer = $this->_atomSerializer;

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_OK,
            Resources::EMPTY_STRING,
            $options
        )->then(function ($response) use ($atomSerializer) {
            return GetTableResult::create($response->getBody(), $atomSerializer);
        }, null);
    }

    /**
     * Deletes the specified table and any data it contains.
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return void
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179387.aspx
     */
    public function deleteTable($table, Models\TableServiceOptions$options = null)
    {
        $this->deleteTableAsync($table, $options)->wait();
    }

    /**
     * Creates promise to delete the specified table and any data it contains.
     *
     * @param string                     $table   The name of the table.
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179387.aspx
     */
    public function deleteTableAsync(
        $table,
        Models\TableServiceOptions$options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');

        $method      = Resources::HTTP_DELETE;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $path        = "Tables('$table')";

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_NO_CONTENT,
            Resources::EMPTY_STRING,
            $options
        );
    }

    /**
     * Quries entities for the given table name
     *
     * @param string                                                   $table   The name of
     * the table.
     * @param Models\QueryEntitiesOptions|string|Models\Filters\Filter $options Coule be
     * optional parameters, query string or filter to apply.
     *
     * @return Models\QueryEntitiesResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179421.aspx
     */
    public function queryEntities($table, $options = null)
    {
        return $this->queryEntitiesAsync($table, $options)->wait();
    }

    /**
     * Quries entities for the given table name
     *
     * @param string                                                   $table   The name of
     * the table.
     * @param Models\QueryEntitiesOptions|string|Models\Filters\Filter $options Coule be
     * optional parameters, query string or filter to apply.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179421.aspx
     */
    public function queryEntitiesAsync($table, $options = null)
    {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');

        $method      = Resources::HTTP_GET;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $path        = $table;

        if (is_null($options)) {
            $options = new QueryEntitiesOptions();
        } elseif (is_string($options)) {
            $queryString = $options;
            $options     = new QueryEntitiesOptions();
            $options->setFilter(Filter::applyQueryString($queryString));
        } elseif ($options instanceof Filter) {
            $filter  = $options;
            $options = new QueryEntitiesOptions();
            $options->setFilter($filter);
        }

        $queryParams = $this->_addOptionalQuery($queryParams, $options->getQuery());

        $this->addOptionalQueryParam(
            $queryParams,
            Resources::QP_NEXT_PK,
            $options->getNextPartitionKey()
        );
        $this->addOptionalQueryParam(
            $queryParams,
            Resources::QP_NEXT_RK,
            $options->getNextRowKey()
        );

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        if (!is_null($options->getQuery())) {
            $dsHeader   = Resources::DATA_SERVICE_VERSION;
            $maxdsValue = Resources::MAX_DATA_SERVICE_VERSION_VALUE;
            $fields     = $options->getQuery()->getSelectFields();
            $hasSelect  = !empty($fields);
            if ($hasSelect) {
                $this->addOptionalHeader($headers, $dsHeader, $maxdsValue);
            }
        }

        $atomSerializer = $this->_atomSerializer;

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_OK,
            Resources::EMPTY_STRING,
            $options
        )->then(function ($response) use ($atomSerializer) {
            $entities = $atomSerializer->parseEntities($response->getBody());

            return QueryEntitiesResult::create(
                HttpFormatter::formatHeaders($response->getHeaders()),
                $entities
            );
        }, null);
    }

    /**
     * Inserts new entity to the table.
     *
     * @param string                     $table   name of the table.
     * @param Models\Entity              $entity  table entity.
     * @param Models\TableServiceOptions $options optional parameters.
     *
     * @return Models\InsertEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179433.aspx
     */
    public function insertEntity(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->insertEntityAsync($table, $entity, $options)->wait();
    }

    /**
     * Inserts new entity to the table.
     *
     * @param string                     $table   name of the table.
     * @param Models\Entity              $entity  table entity.
     * @param Models\TableServiceOptions $options optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179433.aspx
     */
    public function insertEntityAsync(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        $context = $this->_constructInsertEntityContext(
            $table,
            $entity,
            $options
        );

        $atomSerializer = $this->_atomSerializer;

        return $this->sendContextAsync($context)->then(
            function ($response) use ($atomSerializer) {
                $body     = $response->getBody();
                $headers  = HttpFormatter::formatHeaders($response->getHeaders());
                return InsertEntityResult::create(
                    $body,
                    $headers,
                    $atomSerializer
                );
            },
            null
        );
    }

    /**
     * Updates an existing entity or inserts a new entity if it does not exist
     * in the table.
     *
     * @param string                     $table   name of the table
     * @param Models\Entity              $entity  table entity
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return Models\UpdateEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/hh452241.aspx
     */
    public function insertOrMergeEntity(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->insertOrMergeEntityAsync($table, $entity, $options)->wait();
    }

    /**
     * Creates promise to update an existing entity or inserts a new entity if
     * it does not exist in the table.
     *
     * @param string                     $table   name of the table
     * @param Models\Entity              $entity  table entity
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/hh452241.aspx
     */
    public function insertOrMergeEntityAsync(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->_putOrMergeEntityAsyncImpl(
            $table,
            $entity,
            Resources::HTTP_MERGE,
            false,
            $options
        );
    }

    /**
     * Replaces an existing entity or inserts a new entity if it does not exist in
     * the table.
     *
     * @param string                     $table   name of the table
     * @param Models\Entity              $entity  table entity
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return Models\UpdateEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/hh452242.aspx
     */
    public function insertOrReplaceEntity(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->insertOrReplaceEntityAsync(
            $table,
            $entity,
            $options
        )->wait();
    }

    /**
     * Creates a promise to replace an existing entity or inserts a new entity if it does not exist in the table.
     *
     * @param string                     $table   name of the table
     * @param Models\Entity              $entity  table entity
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/hh452242.aspx
     */
    public function insertOrReplaceEntityAsync(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->_putOrMergeEntityAsyncImpl(
            $table,
            $entity,
            Resources::HTTP_PUT,
            false,
            $options
        );
    }

    /**
     * Updates an existing entity in a table. The Update Entity operation replaces
     * the entire entity and can be used to remove properties.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The table entity.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return Models\UpdateEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179427.aspx
     */
    public function updateEntity(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->updateEntityAsync($table, $entity, $options)->wait();
    }

    /**
     * Creates promise to update an existing entity in a table. The Update Entity
     * operation replaces the entire entity and can be used to remove properties.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The table entity.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179427.aspx
     */
    public function updateEntityAsync(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->_putOrMergeEntityAsyncImpl(
            $table,
            $entity,
            Resources::HTTP_PUT,
            true,
            $options
        );
    }

    /**
     * Updates an existing entity by updating the entity's properties. This operation
     * does not replace the existing entity, as the updateEntity operation does.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The table entity.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return Models\UpdateEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179392.aspx
     */
    public function mergeEntity(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->mergeEntityAsync($table, $entity, $options)->wait();
    }

    /**
     * Creates promise to update an existing entity by updating the entity's
     * properties. This operation does not replace the existing entity, as the
     * updateEntity operation does.
     *
     * @param string                     $table   The table name.
     * @param Models\Entity              $entity  The table entity.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179392.aspx
     */
    public function mergeEntityAsync(
        $table,
        Models\Entity $entity,
        Models\TableServiceOptions $options = null
    ) {
        return $this->_putOrMergeEntityAsyncImpl(
            $table,
            $entity,
            Resources::HTTP_MERGE,
            true,
            $options
        );
    }

    /**
     * Deletes an existing entity in a table.
     *
     * @param string                     $table        The name of the table.
     * @param string                     $partitionKey The entity partition key.
     * @param string                     $rowKey       The entity row key.
     * @param Models\DeleteEntityOptions $options      The optional parameters.
     *
     * @return void
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd135727.aspx
     */
    public function deleteEntity(
        $table,
        $partitionKey,
        $rowKey,
        Models\DeleteEntityOptions $options = null
    ) {
        $this->deleteEntityAsync($table, $partitionKey, $rowKey, $options)->wait();
    }

    /**
     * Creates promise to delete an existing entity in a table.
     *
     * @param string                     $table        The name of the table.
     * @param string                     $partitionKey The entity partition key.
     * @param string                     $rowKey       The entity row key.
     * @param Models\DeleteEntityOptions $options      The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd135727.aspx
     */
    public function deleteEntityAsync(
        $table,
        $partitionKey,
        $rowKey,
        Models\DeleteEntityOptions $options = null
    ) {
        $context = $this->_constructDeleteEntityContext(
            $table,
            $partitionKey,
            $rowKey,
            $options
        );

        return $this->sendContextAsync($context);
    }

    /**
     * Gets table entity.
     *
     * @param string                     $table        The name of the table.
     * @param string                     $partitionKey The entity partition key.
     * @param string                     $rowKey       The entity row key.
     * @param Models\TableServiceOptions $options      The optional parameters.
     *
     * @return Models\GetEntityResult
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179421.aspx
     */
    public function getEntity(
        $table,
        $partitionKey,
        $rowKey,
        Models\TableServiceOptions $options = null
    ) {
        return $this->getEntityAsync(
            $table,
            $partitionKey,
            $rowKey,
            $options
        )->wait();
    }

    /**
     * Creates promise to get table entity.
     *
     * @param string                     $table        The name of the table.
     * @param string                     $partitionKey The entity partition key.
     * @param string                     $rowKey       The entity row key.
     * @param Models\TableServiceOptions $options      The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see http://msdn.microsoft.com/en-us/library/windowsazure/dd179421.aspx
     */
    public function getEntityAsync(
        $table,
        $partitionKey,
        $rowKey,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($table, 'table');
        Validate::isTrue(!is_null($partitionKey), Resources::NULL_TABLE_KEY_MSG);
        Validate::isTrue(!is_null($rowKey), Resources::NULL_TABLE_KEY_MSG);

        $method      = Resources::HTTP_GET;
        $headers     = array();
        $queryParams = array();
        $path        = $this->_getEntityPath($table, $partitionKey, $rowKey);

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        $this->addOptionalHeader(
            $headers,
            Resources::CONTENT_TYPE,
            Resources::XML_ATOM_CONTENT_TYPE
        );

        $context = new HttpCallContext();
        $context->setHeaders($headers);
        $context->setMethod($method);
        $context->setPath($path);
        $context->setQueryParameters($queryParams);
        $context->setStatusCodes(array(Resources::STATUS_OK));
        $context->setServiceOptions($options);

        $atomSerializer = $this->_atomSerializer;

        return $this->sendContextAsync($context)->then(
            function ($response) use ($atomSerializer) {
                return GetEntityResult::create(
                    $response->getBody(),
                    $atomSerializer
                );
            },
            null
        );
    }

    /**
     * Does batch of operations on the table service.
     *
     * @param Models\BatchOperations     $batchOperations The operations to apply.
     * @param Models\TableServiceOptions $options         The optional parameters.
     *
     * @return Models\BatchResult
     */
    public function batch(
        Models\BatchOperations $batchOperations,
        Models\TableServiceOptions $options = null
    ) {
        return $this->batchAsync($batchOperations, $options)->wait();
    }

    /**
     * Creates promise that does batch of operations on the table service.
     *
     * @param Models\BatchOperations     $batchOperations The operations to apply.
     * @param Models\TableServiceOptions $options         The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function batchAsync(
        Models\BatchOperations $batchOperations,
        Models\TableServiceOptions $options = null
    ) {
        Validate::notNullOrEmpty($batchOperations, 'batchOperations');

        $method      = Resources::HTTP_POST;
        $operations  = $batchOperations->getOperations();
        $contexts    = $this->_createOperationsContexts($operations);
        $mime        = $this->_createBatchRequestBody($operations, $contexts);
        $body        = $mime['body'];
        $headers     = $mime['headers'];
        $postParams  = array();
        $queryParams = array();
        $path        = '$batch';

        if (is_null($options)) {
            $options = new TableServiceOptions();
        }

        $atomSerializer = $this->_atomSerializer;
        $mimeSerializer = $this->_mimeSerializer;

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);

        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_ACCEPTED,
            $body,
            $options
        )->then(function ($response) use (
            $operations,
            $contexts,
            $atomSerializer,
            $mimeSerializer
        ) {
            return BatchResult::create(
                $response->getBody(),
                $operations,
                $contexts,
                $atomSerializer,
                $mimeSerializer
            );
        }, null);
    }

    /**
     * Gets the access control list (ACL)
     *
     * @param string                     $table   The table name.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return Models\TableACL
     *
     * @see https://docs.microsoft.com/en-us/rest/api/storageservices/fileservices/get-table-acl
     */
    public function getTableAcl(
        $table,
        Models\TableServiceOptions $options = null
    ) {
        return $this->getTableAclAsync($table, $options)->wait();
    }

    /**
     * Creates the promise to gets the access control list (ACL)
     *
     * @param string                     $table   The table name.
     * @param Models\TableServiceOptions $options The optional parameters.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see https://docs.microsoft.com/en-us/rest/api/storageservices/fileservices/get-table-acl
     */
    public function getTableAclAsync(
        $table,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        
        $method      = Resources::HTTP_GET;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $statusCode  = Resources::STATUS_OK;
        $path        = $table;
        
        if (is_null($options)) {
            $options = new TableServiceOptions();
        }
        
        $this->addOptionalQueryParam(
            $queryParams,
            Resources::QP_COMP,
            'acl'
        );

        $dataSerializer = $this->dataSerializer;
        
        $promise = $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_OK,
            Resources::EMPTY_STRING,
            $options
        );

        return $promise->then(function ($response) use ($dataSerializer) {
            $parsed       = $dataSerializer->unserialize($response->getBody());
            return TableACL::create($parsed);
        }, null);
    }
    
    /**
     * Sets the ACL.
     *
     * @param string                     $table   name
     * @param Models\TableACL            $acl     access control list for Table
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return void
     *
     * @see https://docs.microsoft.com/en-us/rest/api/storageservices/fileservices/set-table-acl
     */
    public function setTableAcl(
        $table,
        Models\TableACL $acl,
        Models\TableServiceOptions $options = null
    ) {
        $this->setTableAclAsync($table, $acl, $options)->wait();
    }

    /**
     * Creates promise to set the ACL
     *
     * @param string                     $table   name
     * @param Models\TableACL            $acl     access control list for Table
     * @param Models\TableServiceOptions $options optional parameters
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     *
     * @see https://docs.microsoft.com/en-us/rest/api/storageservices/fileservices/set-table-acl
     */
    public function setTableAclAsync(
        $table,
        Models\TableACL $acl,
        Models\TableServiceOptions $options = null
    ) {
        Validate::isString($table, 'table');
        Validate::notNullOrEmpty($acl, 'acl');
        
        $method      = Resources::HTTP_PUT;
        $headers     = array();
        $postParams  = array();
        $queryParams = array();
        $body        = $acl->toXml($this->dataSerializer);
        $path        = $table;
        
        if (is_null($options)) {
            $options = new TableServiceOptions();
        }
        
        $this->addOptionalQueryParam(
            $queryParams,
            Resources::QP_COMP,
            'acl'
        );

        $options->setLocationMode(LocationMode::PRIMARY_ONLY);
        
        return $this->sendAsync(
            $method,
            $headers,
            $queryParams,
            $postParams,
            $path,
            Resources::STATUS_NO_CONTENT,
            $body,
            $options
        );
    }
}