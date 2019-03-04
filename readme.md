<p align="center"><img src="https://laravel.com/assets/img/components/logo-cashier.svg"></p>

<p align="center">
<a href="https://travis-ci.org/laravel/cashier"><img src="https://travis-ci.org/laravel/cashier.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/cashier"><img src="https://poser.pugx.org/laravel/cashier/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/cashier"><img src="https://poser.pugx.org/laravel/cashier/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/cashier"><img src="https://poser.pugx.org/laravel/cashier/license.svg" alt="License"></a>
</p>

## Introduction

Laravel Cashier provides an expressive, fluent interface to [Stripe's](https://stripe.com) subscription billing services. It handles almost all of the boilerplate subscription billing code you are dreading writing. In addition to basic subscription management, Cashier can handle coupons, swapping subscription, subscription "quantities", cancellation grace periods, and even generate invoice PDFs.

## Official Documentation

Documentation for Cashier can be found on the [Laravel website](https://laravel.com/docs/billing).

## Running Cashier's Tests Locally

You will need to set the following details locally and on your Stripe account in order to run the Cashier unit tests:

### Environment

#### .env

    STRIPE_SECRET=
    STRIPE_MODEL=Laravel\Cashier\Tests\Fixtures\User

You can set these variables in the `phpunit.xml` file.

### Stripe

#### Plans

    * monthly-10-1 ($10)
    * monthly-10-2 ($10)

#### Coupons

    * coupon-1 ($5)

## Contributing

Thank you for considering contributing to the Cashier. You can read the contribution guide lines [here](contributing.md).

## License

Laravel Cashier is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Database

Step 1: subscription creations (newSubscription)
Plan
	- id
	- remote_plan_id
	- billable_card_brand
	- billable_card_last_four

Owner
	- id
	- remote_owner_id

Subscription
	- id
	- local_owner_id
	- local_plan_id

Subscription
    - id
    - gateway
    - remote_id
    - local_owner_id
    - local_plan_id
    - quantity
    - trial_ends_at
    - ends_at

Step 2: subscription recurring ()

Step 3: invoices listing (last invoice, next invoice, invoice list)
    - Customers can quick view their invoices

Step 4: Admin subscription editable

Step 5: trials period

