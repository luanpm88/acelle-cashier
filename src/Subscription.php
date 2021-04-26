<?php

namespace Acelle\Cashier;

use Carbon\Carbon;
use LogicException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Acelle\Cashier\SubscriptionTransaction;

class Subscription extends Model
{
    const STATUS_NEW = 'new';
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_ENDED = 'ended';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at', 'current_period_ends_at',
        'created_at', 'updated_at', 'started_at', 'last_period_ends_at'
    ];

    /**
     * Indicates if the plan change should be prorated.
     *
     * @var bool
     */
    protected $prorate = true;

    /**
     * The date on which the billing cycle should be anchored.
     *
     * @var string|null
     */
    protected $billingCycleAnchor = null;

    /**
     * Bootstrap any application services.
     */
    public static function boot()
    {
        parent::boot();

        // Create uid when creating list.
        static::creating(function ($item) {
            // Create new uid
            $uid = uniqid();
            while (self::where('uid', '=', $uid)->count() > 0) {
                $uid = uniqid();
            }
            $item->uid = $uid;
        });
    }

    /**
     * Find item by uid.
     *
     * @return object
     */
    public static function findByUid($uid)
    {
        return self::where('uid', '=', $uid)->first();
    }

    /**
     * Get metadata.
     *
     * @var object | collect
     */
    public function getMetadata()
    {
        if (!$this['metadata']) {
            return json_decode('{}', true);
        }

        return json_decode($this['metadata'], true);
    }

    /**
     * Get metadata.
     *
     * @var object | collect
     */
    public function updateMetadata($data)
    {
        $metadata = (object) array_merge((array) $this->getMetadata(), $data);
        $this['metadata'] = json_encode($metadata);

        $this->save();
    }

    /**
     * Associations.
     *
     * @var object | collect
     */
    public function plan()
    {
        // @todo dependency injection
        return $this->belongsTo('\Acelle\Model\Plan', 'plan_id', 'uid');
    }

    /**
     * Get the user that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        // @todo dependency injection
        return $this->belongsTo('\Acelle\Model\Customer', 'user_id', 'uid');
    }

    /**
     * Get related invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function invoices()
    {
        $uid = $this->uid;
        return \Acelle\Model\Invoice::whereIn('id', function($query) use ($uid) {
            $query->select('invoice_id')
            ->from(with(new \Acelle\Model\InvoiceItem)->getTable())
            ->where('item_id', $uid);
        });
    }

    /**
     * Get last invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lastInvoice()
    {
        return $this->invoices()
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get pending invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function waitInvoices()
    {
        return $this->invoices()
            ->whereIn('status', \Acelle\Model\Invoice::waitStatuses());
    }

    /**
     * Get pending invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function waitInvoice()
    {
        return $this->waitInvoices()
            ->first();
    }

    /**
     * Get pending invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function waitRenewInvoices()
    {
        return $this->renewInvoices()
            ->whereIn('status', \Acelle\Model\Invoice::waitStatuses());
    }

    /**
     * Get wait renew invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function waitRenewInvoice()
    {
        return $this->waitRenewInvoices()
            ->first();
    }

    /**
     * Get pending invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hasWaitInvoices()
    {
        return $this->waitInvoices()
            ->count();
    }

    /**
     * Get init invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function initInvoices()
    {
        $uid = $this->uid;
        return \Acelle\Model\Invoice::whereIn('id', function($query) use ($uid) {
            $query->select('invoice_id')
            ->from(with(new \Acelle\Model\InvoiceItem)->getTable())
            ->where('item_id', $uid)
            ->where('item_type', \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_INIT);
        })->orderBy('created_at', 'desc');
    }

    /**
     * Get init invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function initInvoice()
    {
        return $this->initInvoices()
            ->first();
    }

    /**
     * Get related invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function renewInvoices()
    {
        $uid = $this->uid;
        return \Acelle\Model\Invoice::whereIn('id', function($query) use ($uid) {
            $query->select('invoice_id')
            ->from(with(new \Acelle\Model\InvoiceItem)->getTable())
            ->where('item_id', $uid)
            ->where('item_type', \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_RENEW);
        })->orderBy('created_at', 'desc');
    }
    

    /**
     * Get renew invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function renewInvoice()
    {
        return $this->renewInvoices()
            ->first();
    }

    /**
     * Get change plan invoices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function changePlanInvoices()
    {
        $uid = $this->uid;
        return \Acelle\Model\Invoice::whereIn('id', function($query) use ($uid) {
            $query->select('invoice_id')
            ->from(with(new \Acelle\Model\InvoiceItem)->getTable())
            ->where('item_id', $uid)
            ->where('item_type', \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_CHANGE_PLAN);
        })->orderBy('created_at', 'desc');
    }

    /**
     * Get change plan invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function changePlanInvoice()
    {
        return $this->changePlanInvoices()
            ->first();
    }

    /**
     * Get change plan invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function changePlanInvoiceItem()
    {
        return $this->changePlanInvoice()->invoiceItems()
            ->where('item_id', $this->uid)->first();
    }

    /**
     * Init new subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public static function initNew($customer, $plan)
    {
        $subscription = $customer->subscription;
        // create tmp subscription
        if (!$subscription) {
            $subscription = new self();
            $subscription->user_id = $customer->getBillableId();
        }
        $subscription->plan_id = $plan->getBillableId();
        $subscription->current_period_ends_at = null;
        $subscription->ends_at = null;
        $subscription->started_at = null;
        $subscription->status = Subscription::STATUS_NEW;
        $subscription->error = null;
        $subscription->save();

        return $subscription;
    }

    /**
     * Create init invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createInitInvoice()
    {
        // 
        if ($this->hasWaitInvoices()) {
            throw new \Exception(trans('messages.error.has_waiting_invoices'));
        }

        // create invoice
        $invoice = new \Acelle\Model\Invoice();
        $invoice->status = \Acelle\Model\Invoice::STATUS_NEW;
        $invoice->description = trans('messages.subscription_init.bill_desc', [
            'plan' => $this->plan->name,
            'date' => $this->getPeriodEndsAt(\Carbon\Carbon::now()),
        ]);
        $invoice->customer_id = $this->user->id;
        $invoice->currency_id = $this->plan->currency_id;
        $invoice->save();

        // add item
        $invoiceItem = new \Acelle\Model\InvoiceItem();
        $invoiceItem->invoice_id = $invoice->id;
        $invoiceItem->item_id = $this->uid;
        $invoiceItem->item_type = \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_INIT;
        $invoiceItem->amount = $this->plan->price;        
        $invoiceItem->save();

        return $invoice;
    }

    /**
     * Create renew invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createRenewInvoice()
    {
        // 
        if ($this->hasWaitInvoices()) {
            throw new \Exception(trans('messages.error.has_waiting_invoices'));
        }

        // create invoice
        $invoice = new \Acelle\Model\Invoice();
        $invoice->status = \Acelle\Model\Invoice::STATUS_NEW;
        $invoice->description = trans('messages.subscription_renew.bill_desc', [
            'plan' => $this->plan->name,
            'date' => $this->nextPeriod(),
        ]);
        $invoice->customer_id = $this->user->id;
        $invoice->currency_id = $this->plan->currency_id;
        $invoice->save();

        // add item
        $invoiceItem = new \Acelle\Model\InvoiceItem();
        $invoiceItem->invoice_id = $invoice->id;
        $invoiceItem->item_id = $this->uid;
        $invoiceItem->item_type = \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_RENEW;
        $invoiceItem->amount = $this->plan->price;        
        $invoiceItem->save();

        return $invoice;
    }

    /**
     * Create change plan invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createChangePlanInvoice($newPlan, $metadata)
    {
        // 
        if ($this->hasWaitInvoices()) {
            throw new \Exception(trans('messages.error.has_waiting_invoices'));
        }

        // create invoice
        $invoice = new \Acelle\Model\Invoice();
        $invoice->status = \Acelle\Model\Invoice::STATUS_NEW;
        $invoice->description = trans('messages.subscription_change_plan.bill_desc', [
            'plan' => $this->plan->name,
            'newPlan' => $newPlan->name,
            'date' => \Acelle\Library\Tool::formatDate(\Carbon\Carbon::parse($metadata['endsAt'])),
        ]);
        $invoice->customer_id = $this->user->id;
        $invoice->currency_id = $this->plan->currency_id;
        $invoice->save();

        // add item
        $invoiceItem = new \Acelle\Model\InvoiceItem();
        $invoiceItem->invoice_id = $invoice->id;
        $invoiceItem->item_id = $this->uid;
        $invoiceItem->item_type = \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_CHANGE_PLAN;
        $invoiceItem->amount = $metadata['amount'];        
        $invoiceItem->save();

        // update data
        $invoiceItem->updateMetadata($metadata);

        return $invoice;
    }

    /**
     * Set subscription as ended.
     *
     * @return bool
     */
    public function setEnded()
    {
        // then set the sub end
        $this->status = self::STATUS_ENDED;
        $this->ends_at = \Carbon\Carbon::now();
        $this->save();
    }

    /**
     * Get lastest bill information
     *
     * @return void
     */
    public function getUpcomingBillingInfo()
    {
        if ($this->cancelled()) {
            return null;
        }

        if (!$this->canRenewPlan()) {
            return null;
        }

        // has wait renew invoice
        if ($this->waitRenewInvoice()) {
            return $this->waitRenewInvoice()->getBillingInfo();
        } else {
            return [
                'title' => trans('messages.upcoming_bill.title'),
                'description' => trans('messages.upcoming_bill.desc', [
                    'plan' => $this->plan->name,
                    'date' => $this->current_period_ends_at,
                ]),
                'bill' => [
                    [
                        'name' => $this->plan->name,
                        'desc' => view('plans._bill_desc', ['plan' => $this->plan]),
                        'price' => format_price($this->plan->price, $this->plan->currency->format),
                        'tax' => format_price(0, $this->plan->currency->format),
                        'discount' => format_price(0, $this->plan->currency->format),
                    ]
                ],
                'charge_info' => $this->user->paymentCanAutoCharge() ? trans('messages.bill.auto_charge', ['date' => $this->nextPeriod()]) : trans('messages.bill.charge_before', ['date' => $this->nextPeriod()]),
                'total' => format_price($this->plan->price, $this->plan->currency->format),
            ];
        }

        
    }

    /**
     * Get period by start date.
     *
     * @param  date  $date
     * @return date
     */
    public function getPeriodEndsAt($startDate)
    {        
        // dose not support recurring, update ends at column
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $endsAt = $startDate->addMonthsNoOverflow($intervalCount);
                break;
            case 'day':
                $endsAt = $startDate->addDay($intervalCount);
            case 'week':
                $endsAt = $startDate->addWeek($intervalCount);
                break;
            case 'year':
                $endsAt = $startDate->addYearsNoOverflow($intervalCount);
                break;
            default:
                $endsAt = null;
        }

        return $endsAt;
    }

    /**
     * Set as new.
     *
     * @return void
     */
    public function setNew()
    {
        $this->status = self::STATUS_NEW;
        $this->save();
    }

    /**
     * Set as pending.
     *
     * @return void
     */
    public function setPending()
    {
        $this->status = self::STATUS_PENDING;
        $this->save();
    }

    /**
     * Check last invoice.
     *
     * @return void
     */
    public function checkLastInvoice()
    {
        $lastInvoice = $this->lastInvoice();

        // return if null
        if (!$lastInvoice) {
            return;
        }

        // REJECTED ANNOUNCEMENT
        if (!$this->hasError() || $this->hasError('invoice_rejected')) {
            // rejected annouce
            if ($lastInvoice->isRejected()) {
                // add error notice
                $this->setError([
                    'status' => 'warning',
                    'type' => 'invoice_rejected',
                    'message' => $lastInvoice->getMetadata('reason'),
                ]);
            }
        }        

        // RENEW INVOICE CHECK
        if ($lastInvoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_RENEW) {
            $renewInvoice = $lastInvoice;

            // RENEW FAILED
            $lastTransaction = $renewInvoice->lastTransaction();
            if ($lastTransaction && $lastTransaction->isFailed()) {
                if ($this->user->getPaymentMethod()) {
                    // last transaction error
                    $this->setError([
                        'status' => 'error',
                        'type' => 'renew_transaction_failed',
                        'message' => trans('messages.invoice.renew_transaction_failed.reconnect', [
                            'error' => $lastTransaction->error,
                            'link' => $this->user->getPaymentGateway()->getConnectUrl(
                                action('AccountSubscriptionController@index'),
                            ),
                        ]),
                    ]);
                } else {
                    // last transaction error
                    $this->setError([
                        'status' => 'error',
                        'type' => 'renew_transaction_failed',
                        'message' => trans('messages.invoice.renew_transaction_failed', [
                            'error' => $lastTransaction->error,
                        ]),
                    ]);
                }
            } else {
                $this->removeError('renew_transaction_failed');
            }

            // SERVICE AUTO CHARGE
            if (
                // service can auto charge
                $this->user->paymentCanAutoCharge() &&
                $renewInvoice->isWait() && 
                // check charge day before
                \Carbon\Carbon::now()->greaterThanOrEqualTo($this->current_period_ends_at->subDays(config('cashier.recurring_charge_before_days')))
            ) {
                $this->user->getPaymentGateway()->charge($renewInvoice);
            }

            // Check if user need to pay renew invoice
            if($renewInvoice->isNew() && !$this->user->paymentCanAutoCharge()) {
                $this->setError([
                    'status' => 'warning',
                    'type' => 'renew',
                    'message' => trans('cashier::messages.renew.warning', [
                        'date' => $this->current_period_ends_at,
                        'link' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@renew'),
                    ]),
                ]);
                $this->save();
            } else {
                $this->removeError('renew');
            }

            // Check if user has pending renew invoice
            if ($renewInvoice->isPending() || $renewInvoice->isClaimed()) {
                // add error notice
                $this->setError([
                    'status' => 'warning',
                    'type' => 'renew_pending',
                    'message' => trans('cashier::messages.invoice.subscription.renew_pending', [
                        'amount' => $renewInvoice->getBillingInfo()['total'],
                        'url' => \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\DirectController@checkout', [
                            'invoice_uid' => $renewInvoice->uid,
                        ]),
                    ]),
                ]);
            } else {
                $this->removeError('renew_pending');
            }

            // renew paid
            if ($renewInvoice->isPaid()) {
                $this->renew();

                // set done
                $renewInvoice->setDone();
            }
        }

        // RENEW INVOICE CHECK
        if ($lastInvoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_CHANGE_PLAN) {
            // change plan invoice
            $changePlanInvoice = $lastInvoice;
            
            $changePlanInvoiceItem = $this->changePlanInvoiceItem();
            $data = $changePlanInvoiceItem->getMetadata();
            $newPlan = \Acelle\Model\Plan::findByUid($data['plan_uid']);

            // Check if user need to change plan subscription
            if($changePlanInvoice->isNew()) {
                $this->setError([
                    'status' => 'warning',
                    'type' => 'change_plan',
                    'message' => trans('cashier::messages.change_plan.warning', [
                        'link' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@changePlanPayment'),
                    ]),
                ]);
                $this->save();
            } else {
                $this->removeError('change_plan');
            }

            // Check if user has pending renew invoice
            if ($changePlanInvoice->isPending() || $changePlanInvoice->isClaimed()) {
                // add error notice
                $this->setError([
                    'status' => 'warning',
                    'type' => 'change_plan_pending',
                    'message' => trans('cashier::messages.invoice.subscription.change_plan_pending', [
                        'amount' => $changePlanInvoice->getBillingInfo()['total'],
                        'url' => \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\DirectController@checkout', [
                            'invoice_uid' => $changePlanInvoice->uid,
                        ]),
                    ]),
                ]);
            } else {
                $this->removeError('change_plan_pending');
            }

            // change plan invoice paid
            if ($changePlanInvoice->isPaid()) {
                $this->changePlan($newPlan, $data['endsAt']);

                // set done
                $changePlanInvoice->setDone();
            }
        }
    }

    /**
     * Check subscription status.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function check()
    {
        // when NEW
        if ($this->isNew()) {
            $initInvoice = $this->initInvoice();

            // create init invoice if not exist
            if (!$initInvoice || !$initInvoice->isWait()) {
                $initInvoice = $this->createInitInvoice();
            }

            // invoice is new
            if ($initInvoice->isNew()) {
                $this->setNew();
            }

            // check if init invoice claimed
            if ($initInvoice->isClaimed()) {
                $this->setPending();
            }

            // invoice is new
            if ($initInvoice->isPaid()) {
                $this->setActive();

                // set done
                $initInvoice->setDone();

                // add log
                $this->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                    'plan' => $this->plan->getBillableName(),
                    'price' => $this->plan->getBillableFormattedPrice(),
                ]);
            }
        }

        // when PENDING
        if ($this->isPending()) {
            $initInvoice = $this->initInvoice();

            // invoice is new
            if ($initInvoice->isPaid()) {
                $this->setActive();

                // set done
                $initInvoice->setDone();
            }
        }
        
        // when ACTIVE
        if ($this->isActive()) {
            // CHECK EXPIRING
            if ($this->isExpiring() && $this->canRenewPlan()) {
                // create renew invoice if not exist
                if (!$this->hasWaitInvoices()) {
                    $this->createRenewInvoice();
                }
            }

            // LAST INVOICE CHECKING
            $this->checkLastInvoice();
        }

        // ALL STATUSES
        // check expired
        if ($this->isExpired()) {
            $this->cancelNow();

            // add log
            $this->addLog(SubscriptionLog::TYPE_EXPIRED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
            ]);
        }
    }
    
    /**
     * Change plan.
     */
    public function changePlan($newPlan, $endsAt)
    {
        $this->plan_id = $newPlan->uid;
        $this->current_period_ends_at = $endsAt;    
        
        // ends at
        if ($this->ends_at != null) {
            $this->ends_at = $this->current_period_ends_at;
        }

        $this->save();

        // logs
        $this->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
            'old_plan' => $this->plan->getBillableName(),
            'plan' => $newPlan->getBillableName(),
            'price' => $newPlan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Approve pending invoice.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approveWaitInvoice()
    {
        $invoice = $this->waitInvoice();

        // can not find init invoice
        if (!$invoice) {
            throw new \Exception('Can not find wait (pending|claimed) invoice');
        }

        $invoice->setPaid();


        // transaction
        $invoice->addTransaction([
            'status' => \Acelle\Model\Transaction::STATUS_SUCCESS,
            'message' => trans('messages.pay_invoice', [
                'id' => $invoice->uid,
                'title' => $invoice->getBillingInfo()['title'],
            ]),
        ]);

        // add log
        if ($invoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_INIT) {
            $this->addLog(SubscriptionLog::TYPE_ADMIN_APPROVED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
            ]);
        }

        // add log
        if ($invoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_RENEW) {
            $this->addLog(SubscriptionLog::TYPE_ADMIN_RENEW_APPROVED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
            ]);
        }

        // add log
        if ($invoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_CHANGE_PLAN) {
            $this->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_CHANGE_APPROVED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
            ]);
        }

        // check subscription
        $this->check();
    }

    /**
     * Reject pending invoice.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function rejectWaitInvoice($reason=null)
    {
        $invoice = $this->waitInvoice();

        // can not find init invoice
        if (!$invoice) {
            throw new \Exception('Can not find wait (pending|claimed) invoice');
        }

        $invoice->setRejected($reason);

        // add log
        if ($invoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_RENEW) {
            $this->addLog(SubscriptionLog::TYPE_ADMIN_RENEW_REJECTED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
                'reason' => $reason,
            ]);
        }

        // add log
        if ($invoice->getType() == \Acelle\Model\InvoiceItem::TYPE_SUBSCRIPTION_CHANGE_PLAN) {
            $this->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_CHANGE_REJECTED, [
                'plan' => $this->plan->getBillableName(),
                'price' => $this->plan->getBillableFormattedPrice(),
                'reason' => $reason,
            ]);
        }

        // check subscription
        $this->check();
    }

    /**
     * Get subscription meaning status.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getShowStatus()
    {
        // others
        if ($this->isNew() || $this->isPending() || $this->isEnded()) {
            return $this->status;
        }

        // active
        if ($this->isActive()) {
            if ($this->renewInvoice() && $this->renewInvoice()->isNew()) {
                return 'renew_pending';
            }

            if ($this->renewInvoice() && $this->renewInvoice()->isPending()) {
                return 'renew_pending';
            }

            if ($this->renewInvoice() && $this->renewInvoice()->isClaimed()) {
                return 'renew_claimed';
            }

            if ($this->changePlanInvoice() && $this->changePlanInvoice()->isNew()) {
                return 'change_plan_pending';
            }

            if ($this->changePlanInvoice() && $this->changePlanInvoice()->isPending()) {
                return 'change_plan_pending';
            }

            if ($this->changePlanInvoice() && $this->changePlanInvoice()->isClaimed()) {
                return 'change_plan_claimed';
            }
        }

        return $this->status;
    }

    /**
     * Check subscription status.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public static function checkAll()
    {
        $subscriptions = self::whereNull('ends_at')->orWhere('ends_at', '>=', \Carbon\Carbon::now())->get();
        foreach ($subscriptions as $subscription) {
            $subscription->check();

            // // check expired
            // if ($subscription->isExpired()) {
            //     $subscription->cancelNow();

            //     // add log
            //     $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
            //         'plan' => $subscription->plan->getBillableName(),
            //         'price' => $subscription->plan->getBillableFormattedPrice(),
            //     ]);
            // }

            // // get sub gateway
            // $subGateway = $subscription->user->getPaymentGateway();

            // // can not find payment gateway
            // if (!isset($subGateway)) {
            //     // set subscription last_error_type
            //     $subscription->setError([
            //         'status' => 'error',
            //         'type' => 'payment_missing',
            //         'message' => trans('cashier::messages.gateway_not_found'),
            //     ]);

            //     break;
            // } else {
            //     $subscription->removeError('payment_missing');                
            // }

            // // normal check
            // $subGateway->check($subscription);

            // // check plans
            // if (method_exists($subGateway, 'checkAll')) {
            //     $subGateway->checkAll();
            // }
        }
    }
















    // /**
    //  * Associations.
    //  *
    //  * @var object | collect
    //  */
    // public function subscriptionTransactions()
    // {
    //     // @todo dependency injection
    //     return $this->hasMany('\Acelle\Cashier\SubscriptionTransaction');
    // }

    /**
     * Associations.
     *
     * @var object | collect
     */
    public function subscriptionLogs()
    {
        // @todo dependency injection
        return $this->hasMany('\Acelle\Cashier\SubscriptionLog');
    }

    /**
     * Get all transactions from invoices.
     */
    public function transactions()
    {
        $uid = $this->uid;
        return \Acelle\Model\Transaction::whereIn('invoice_id', $this->invoices()->select('id'))
            ->orderBy('created_at', 'desc');
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     *
     * @return bool
     */
    public function isRecurring()
    {
        return ! $this->cancelled();
    }

    // /**
    //  * Get the model related to the subscription.
    //  *
    //  * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
    //  */
    // public function owner()
    // {
    //     $class = Cashier::stripeModel();

    //     return $this->belongsTo($class, (new $class)->getForeignKey());
    // }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status == self::STATUS_ACTIVE;
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isNew()
    {
        return $this->status == self::STATUS_NEW;
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status == self::STATUS_PENDING;
    }

    // /**
    //  * Determine if the subscription is active.
    //  *
    //  * @return bool
    //  */
    // public function isDone()
    // {
    //     return $this->status == self::STATUS_DONE;
    // }

    // /**
    //  * Determine if the subscription is recurring and not on trial.
    //  *
    //  * @return bool
    //  */
    // public function recurring()
    // {
    //     return ! $this->onTrial() && ! $this->cancelled();
    // }



    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is ended.
     *
     * @return bool
     */
    public function isEnded()
    {
        return $this->status == self::STATUS_ENDED;
    }

    /**
     * Determine if the subscription is pending.
     *
     * @return bool
     */
    public function setActive()
    {
        $this->current_period_ends_at = $this->getPeriodEndsAt(Carbon::now());
        $this->ends_at = null;
        $this->status = self::STATUS_ACTIVE;
        $this->started_at = \Carbon\Carbon::now();
        $this->save();
    }

    // /**
    //  * Determine if the subscription is within its trial period.
    //  *
    //  * @return bool
    //  */
    // public function onTrial()
    // {
    //     return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    // }

    // /**
    //  * Determine if the subscription is within its grace period after cancellation.
    //  *
    //  * @return bool
    //  */
    // public function onGracePeriod()
    // {
    //     return $this->ends_at && $this->ends_at->isFuture();
    // }

    // /**
    //  * Indicate that the plan change should not be prorated.
    //  *
    //  * @return $this
    //  */
    // public function noProrate()
    // {
    //     $this->prorate = false;

    //     return $this;
    // }

    // /**
    //  * Change the billing cycle anchor on a plan change.
    //  *
    //  * @param  \DateTimeInterface|int|string  $date
    //  * @return $this
    //  */
    // public function anchorBillingCycleOn($date = 'now')
    // {
    //     if ($date instanceof DateTimeInterface) {
    //         $date = $date->getTimestamp();
    //     }

    //     $this->billingCycleAnchor = $date;

    //     return $this;
    // }

    // /**
    //  * Force the trial to end immediately.
    //  *
    //  * This method must be combined with swap, resume, etc.
    //  *
    //  * @return $this
    //  */
    // public function skipTrial()
    // {
    //     $this->trial_ends_at = null;

    //     return $this;
    // }

    // /**
    //  * Mark the subscription as cancelled.
    //  *
    //  * @return void
    //  */
    // public function markAsCancelled()
    // {
    //     $this->fill(['ends_at' => Carbon::now()->startOfDay()])->save();
    // }

    /**
     * Next one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function nextPeriod()
    {
        return $this->getPeriodEndsAt($this->current_period_ends_at);
    }

    /**
     * Next one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function periodStartAt()
    {
        $startAt = $this->current_period_ends_at;
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $startAt = $startAt->subMonthsNoOverflow($intervalCount);
                break;
            case 'day':
                $startAt = $startAt->subDay($intervalCount);
            case 'week':
                $startAt = $startAt->subWeek($intervalCount);
                break;
            case 'year':
                $startAt = $startAt->subYearsNoOverflow($intervalCount);
                break;
            default:
                $startAt = null;
        }

        return $startAt;
    }

    // /**
    //  * Add one period to subscription.
    //  *
    //  * @param  Gateway    $gateway
    //  * @return Boolean
    //  */
    // public function addPeriod()
    // {
    //     $this->ends_at = $this->nextPeriod();
    //     $this->save();
    // }

    // /**
    //  * Check if payment is claimed.
    //  *
    //  * @param  Int  $subscriptionId
    //  * @return date
    //  */
    // public function isPaymentClaimed()
    // {
    //     return $this->payment_claimed;
    // }

    // /**
    //  * Claim payment.
    //  *
    //  * @param  Int  $subscriptionId
    //  * @return date
    //  */
    // public function claimPayment()
    // {
    //     $this->payment_claimed = true;
    //     $this->save();
    // }

    

    /**
     * Check if subscription is expired.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function isExpired()
    {
        return isset($this->ends_at) && \Carbon\Carbon::now()->endOfDay() > $this->ends_at;
    }
    
    // /**
    //  * Start subscription.
    //  *
    //  * @param  date  $date
    //  * @return date
    //  */
    // public function start()
    // {
    //     $this->ends_at = null;
    //     $this->current_period_ends_at = $this->getPeriodEndsAt(\Carbon\Carbon::now());
    //     $this->status = self::STATUS_ACTIVE;
    //     $this->started_at = \Carbon\Carbon::now();
    //     $this->save();
    // }

    // /**
    //  * Subscription transactions.
    //  *
    //  * @return array
    //  */
    // public function getTransactions()
    // {
    //     return $this->subscriptionTransactions()->orderBy('created_at', 'desc')->get();
    // }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function getLogs()
    {
        return $this->subscriptionLogs()->orderBy('created_at', 'desc')->get();
    }

    // /**
    //  * Subscription transactions.
    //  *
    //  * @return array
    //  */
    // public function addTransaction($type, $data)
    // {
    //     $transaction = new SubscriptionTransaction();
    //     $transaction->subscription_id = $this->id;
    //     $transaction->type = $type;
    //     $transaction->fill($data);

    //     if (isset($data['metadata'])) {
    //         $transaction['metadata'] = json_encode($data['metadata']);
    //     }

    //     $transaction->save();

    //     return $transaction;
    // }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function addLog($type, $data, $transaction_id=null)
    {
        $log = new SubscriptionLog();
        $log->subscription_id = $this->id;
        $log->type = $type;
        $log->transaction_id = $transaction_id;
        $log->save();

        if (isset($data)) {
            $log->updateData($data);
        }

        return $log;
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function cancel()
    {
        $this->ends_at = $this->current_period_ends_at;
        $this->save();
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function resume()
    {
        $this->ends_at = null;
        $this->save();
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function cancelNow()
    {
        // set status = ended
        $this->setEnded();

        // cancel all wait invoices
        $this->waitInvoices()->update([
            'status' => \Acelle\Model\Invoice::STATUS_CANCELED,
        ]);

        // add log
        $this->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
            'plan' => $this->plan->getBillableName(),
            'price' => $this->plan->getBillableFormattedPrice(),
        ]);
    }

    // public function changePlan($newPlan, $amount=null) {
    //     // calc when change plan
    //     $result = Cashier::calcChangePlan($this, $newPlan);

    //     // set new amount to plan
    //     $newPlan->price = $result['amount'];
    //     if ($amount) {
    //         $newPlan->price = $amount;
    //     }

    //     // update subscription date
    //     if ($this->current_period_ends_at != $result['endsAt']) {
    //         // save last period
    //         $this->last_period_ends_at = $this->current_period_ends_at;
    //         // set new current period
    //         $this->current_period_ends_at = $result['endsAt'];
    //     }

    //     if (isset($this->ends_at) && $this->ends_at < $result['endsAt']) {
    //         $this->ends_at = $result['endsAt'];
    //     }
    //     $this->plan_id = $newPlan->getBillableId();
    //     $this->save();
    // }


    /**
     * Renew subscription
     *
     * @return void
     */
    public function renew() {
        // set new current period
        $this->current_period_ends_at = $this->getPeriodEndsAt($this->current_period_ends_at);    
        
        // ends at
        if ($this->ends_at != null) {
            $this->ends_at = $this->current_period_ends_at;
        }

        $this->save();

        // logs
        $this->addLog(SubscriptionLog::TYPE_RENEWED, [
            'plan' => $this->plan->getBillableName(),
            'price' => $this->plan->getBillableFormattedPrice(),
        ]);
    }

    public function isExpiring() {
        // check if recurring accur
        if (\Carbon\Carbon::now()->greaterThanOrEqualTo($this->current_period_ends_at->subDays(config('cashier.end_period_last_days')))) {
            return true;
        }

        return false;
    }

    /**
     * Check if has error
     *
     * @return void
     */
    public function hasError($type=null)
    {
        if ($this->getError($type) == null) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get error
     *
     * @return void
     */
    public function getError($type=null)
    {
        if (!$this->error) {
            return null;
        }

        $error = json_decode($this->error, true);

        if ($type != null) {
            if (isset($error['type']) && $error['type'] == $type) {
                return $error;
            } else {
                return null;
            }
        } else {
            return $error;
        }
    }

    // /**
    //  * Check if is renew pending
    //  *
    //  * @return void
    //  */
    // public function hasRenewPending()
    // {
    //     return ($this->hasError() &&  in_array($this->getError()["type"], ['renew']));
    // }

    // /**
    //  * Check if is renew pending
    //  *
    //  * @return void
    //  */
    // public function hasChangePlanPending()
    // {
    //     return ($this->hasError() &&  in_array($this->getError()["type"], ['change_plan']));
    // }

    /**
     * Check if can renew free plan
     *
     * @return void
     */
    public function canRenewPlan()
    {
        return ($this->plan->getBillableAmount() > 0 ||
            (config('cashier.renew_free_plan') == 'yes' && $this->plan->getBillableAmount() == 0)
        );
    }

    /**
     * Add error
     *
     * @return void
     */
    public function setError($data)
    {
        $this->error = json_encode($data);
        $this->save();
    }

    /**
     * remove error
     *
     * @return void
     */
    public function removeError($type=null)
    {
        if ($type) {
            if (
                $this->getError($type) != null
            ) {
                $this->error = null;
            }
        } else {
            $this->error = null;
        }

        $this->save();
    }

    // /**
    //  * Get upcomming bill information
    //  *
    //  * @return void
    //  */
    // public function getUpComingBill()
    // {
    //     return  [
    //         'plan' => $this->plan,
    //         'bill' => [
    //             ['name' => $this->plan->getBillableName(), 'amount' => $this->plan->getBillableAmount(), 'plan' => $this->plan],
    //             ['name' => trans('messages.bill.tax'), 'amount' => 0],
    //         ],
    //         'charge_on' => $this->current_period_ends_at,
    //         'total' => $this->plan->getBillableAmount(),
    //     ];
    // }

    // /**
    //  * Get lastest bill information
    //  *
    //  * @return void
    //  */
    // public function getLatestBillingInfo()
    // {
    //     return  [
    //         'plan' => $this->plan,
    //         'bill' => [
    //             ['name' => $this->plan->getBillableName(), 'amount' => $this->plan->getBillableAmount(), 'plan' => $this->plan],
    //             ['name' => trans('messages.bill.tax'), 'amount' => 0],
    //         ],
    //         'charge_on' => $this->started_at,
    //         'total' => $this->plan->getBillableAmount(),
    //     ];
    // }
    
    // /**
    //  * Get lastest bill information
    //  *
    //  * @return void
    //  */
    // public function getRenewBillingInfo()
    // {
    //     return  [
    //         'plan' => $this->plan,
    //         'bill' => [
    //             ['name' => $this->plan->getBillableName(), 'amount' => $this->plan->getBillableAmount(), 'plan' => $this->plan],
    //             ['name' => trans('messages.bill.tax'), 'amount' => 0],
    //         ],
    //         'charge_on' => $this->nextPeriod()->format('d M, Y'),
    //         'total' => $this->plan->getBillableAmount(),
    //     ];
    // }
}
