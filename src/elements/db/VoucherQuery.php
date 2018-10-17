<?php
namespace verbb\giftvoucher\elements\db;

use verbb\giftvoucher\GiftVoucher;
use verbb\giftvoucher\elements\Voucher;
use verbb\giftvoucher\models\VoucherTypeModel;

use Craft;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;

use DateTime;
use yii\db\Connection;

class VoucherQuery extends ElementQuery
{
    // Properties
    // =========================================================================

    public $editable = false;
    public $typeId;
    public $postDate;
    public $expiryDate;


    // Public Methods
    // =========================================================================

    public function __construct(string $elementType, array $config = [])
    {
        // Default status
        if (!isset($config['status'])) {
            $config['status'] = Voucher::STATUS_LIVE;
        }

        parent::__construct($elementType, $config);
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'type':
                $this->type($value);
                break;
            case 'before':
                $this->before($value);
                break;
            case 'after':
                $this->after($value);
                break;
            default:
                parent::__set($name, $value);
        }
    }

    public function type($value)
    {
        if ($value instanceof VoucherType) {
            $this->typeId = $value->id;
        } else if ($value !== null) {
            $this->typeId = (new Query())
                ->select(['id'])
                ->from(['{{%giftvoucher_vouchertypes}}'])
                ->where(Db::parseParam('handle', $value))
                ->column();
        } else {
            $this->typeId = null;
        }

        return $this;
    }

    public function before($value)
    {
        if ($value instanceof DateTime) {
            $value = $value->format(DateTime::W3C);
        }

        $this->postDate = ArrayHelper::toArray($this->postDate);
        $this->postDate[] = '<'.$value;

        return $this;
    }

    public function after($value)
    {
        if ($value instanceof DateTime) {
            $value = $value->format(DateTime::W3C);
        }

        $this->postDate = ArrayHelper::toArray($this->postDate);
        $this->postDate[] = '>='.$value;

        return $this;
    }

    public function editable(bool $value = true)
    {
        $this->editable = $value;

        return $this;
    }

    public function typeId($value)
    {
        $this->typeId = $value;

        return $this;
    }

    public function postDate($value)
    {
        $this->postDate = $value;

        return $this;
    }

    public function expiryDate($value)
    {
        $this->expiryDate = $value;

        return $this;
    }

    // Protected Methods
    // =========================================================================

    protected function beforePrepare(): bool
    {
        // See if 'type' were set to invalid handles
        if ($this->typeId === []) {
            return false;
        }

        $this->joinElementTable('giftvoucher_vouchers');

        $this->query->select([
            'giftvoucher_vouchers.id',
            'giftvoucher_vouchers.typeId',
            'giftvoucher_vouchers.taxCategoryId',
            'giftvoucher_vouchers.shippingCategoryId',
            'giftvoucher_vouchers.postDate',
            'giftvoucher_vouchers.expiryDate',
            'giftvoucher_vouchers.sku',
            // 'giftvoucher_vouchers.promotable',
            'giftvoucher_vouchers.price',
            'giftvoucher_vouchers.customAmount'
        ]);

        if ($this->postDate) {
            $this->subQuery->andWhere(Db::parseDateParam('giftvoucher_vouchers.postDate', $this->postDate));
        }

        if ($this->expiryDate) {
            $this->subQuery->andWhere(Db::parseDateParam('giftvoucher_vouchers.expiryDate', $this->expiryDate));
        }

        if ($this->typeId) {
            $this->subQuery->andWhere(Db::parseParam('giftvoucher_vouchers.typeId', $this->typeId));
        }

        $this->_applyEditableParam();

        if (!$this->orderBy) {
            $this->orderBy = ['postDate' => SORT_DESC];
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status)
    {
        $currentTimeDb = Db::prepareDateForDb(new \DateTime());

        switch ($status) {
            case Voucher::STATUS_LIVE:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true
                    ],
                    ['<=', 'giftvoucher_vouchers.postDate', $currentTimeDb],
                    [
                        'or',
                        ['giftvoucher_vouchers.expiryDate' => null],
                        ['>', 'giftvoucher_vouchers.expiryDate', $currentTimeDb]
                    ]
                ];
            case Voucher::STATUS_PENDING:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true,
                    ],
                    ['>', 'giftvoucher_vouchers.postDate', $currentTimeDb]
                ];
            case Voucher::STATUS_EXPIRED:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true
                    ],
                    ['not', ['giftvoucher_vouchers.expiryDate' => null]],
                    ['<=', 'giftvoucher_vouchers.expiryDate', $currentTimeDb]
                ];
            default:
                return parent::statusCondition($status);
        }
    }

    // Private Methods
    // =========================================================================

    private function _applyEditableParam()
    {
        if (!$this->editable) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            throw new QueryAbortedException();
        }

        // Limit the query to only the sections the user has permission to edit
        $this->subQuery->andWhere([
            'giftvoucher_vouchers.typeId' => GiftVoucher::$plugin->getVoucherTypes()->getEditableVoucherTypeIds()
        ]);
    }
}
