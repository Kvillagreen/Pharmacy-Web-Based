<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelContractTest extends TestCase
{
    public static function models(): array
    {
        return [
            ['Batch', 'batches', 'batch_id'],
            ['BatchHistory', 'batch_histories', 'batch_history_id'],
            ['Bir2306Record', 'bir_2306_records', 'bir_2306_record_id'],
            ['Branch', 'branches', 'branch_id'],
            ['Company', 'companies', 'company_id'],
            ['Inventory', 'inventories', 'inventory_id'],
            ['InventoryTransfer', 'inventory_transfers', 'inventory_transfer_id'],
            ['Medicine', 'medicines', 'medicine_id'],
            ['PasswordResetCode', 'password_reset_codes', 'password_reset_code_id'],
            ['Permission', 'permissions', 'permission_id'],
            ['RegulatedCustomer', 'regulated_customers', 'regulated_customer_id'],
            ['SmsMessage', 'sms_messages', 'sms_message_id'],
            ['SmsOrder', 'sms_orders', 'sms_order_id'],
            ['SmsOrderItem', 'sms_order_items', 'sms_order_item_id'],
            ['SuperAdmin', 'super_admins', 'super_admin_id'],
            ['SystemAuditLog', 'system_audit_logs', 'system_audit_log_id'],
            ['Transaction', 'transactions', 'transaction_id'],
            ['TransactionAttachment', 'transaction_attachments', 'transaction_attachment_id'],
            ['TransactionItem', 'transaction_items', 'transaction_item_id'],
            ['User', 'users', 'user_id'],
            ['UserNotification', 'user_notifications', 'user_notification_id'],
        ];
    }

    #[DataProvider('models')]
    public function test_every_backend_model_has_the_expected_database_contract(
        string $name,
        string $table,
        string $primaryKey
    ): void {
        $class = "App\\Models\\v1\\{$name}";
        $model = new $class();

        $this->assertInstanceOf(Model::class, $model);
        $this->assertSame($table, $model->getTable());
        $this->assertSame($primaryKey, $model->getKeyName());
        $this->assertNotEmpty($model->getFillable(), "{$name} must explicitly define mass-assignable fields");
    }

    public static function relationships(): array
    {
        return [
            ['Batch', 'inventories'],
            ['Branch', 'company'],
            ['Company', 'branches'],
            ['Inventory', 'medicine'], ['Inventory', 'batch'], ['Inventory', 'branch'],
            ['InventoryTransfer', 'medicine'], ['InventoryTransfer', 'batch'],
            ['InventoryTransfer', 'fromBranch'], ['InventoryTransfer', 'toBranch'],
            ['InventoryTransfer', 'requester'], ['InventoryTransfer', 'resolver'],
            ['Medicine', 'inventories'], ['Medicine', 'transactionItems'],
            ['PasswordResetCode', 'user'], ['Permission', 'users'],
            ['RegulatedCustomer', 'transactions'], ['SmsOrder', 'items'],
            ['SmsOrderItem', 'medicine'], ['SuperAdmin', 'creator'],
            ['SystemAuditLog', 'user'], ['SystemAuditLog', 'superAdmin'],
            ['Transaction', 'user'], ['Transaction', 'items'], ['Transaction', 'attachments'],
            ['Transaction', 'branch'], ['Transaction', 'regulatedCustomer'],
            ['TransactionAttachment', 'transaction'], ['TransactionAttachment', 'uploader'],
            ['TransactionItem', 'medicine'], ['TransactionItem', 'batch'],
            ['User', 'transactions'], ['User', 'branch'], ['User', 'permissions'], ['User', 'notifications'],
            ['UserNotification', 'user'], ['UserNotification', 'branch'],
        ];
    }

    #[DataProvider('relationships')]
    public function test_declared_model_relationships_return_eloquent_relations(
        string $name,
        string $method
    ): void {
        $class = "App\\Models\\v1\\{$name}";
        $model = new $class();

        $this->assertTrue(method_exists($model, $method), "{$name}::{$method} relationship is missing");
        $this->assertInstanceOf(Relation::class, $model->{$method}());
    }

    public static function casts(): array
    {
        return [
            ['BatchHistory', 'meta', 'array'],
            ['Bir2306Record', 'amount_of_payment', 'decimal:2'],
            ['Bir2306Record', 'tax_withheld', 'decimal:2'],
            ['InventoryTransfer', 'confirmed_at', 'datetime'],
            ['PasswordResetCode', 'expires_at', 'datetime'],
            ['RegulatedCustomer', 'last_purchase_at', 'datetime'],
            ['SmsMessage', 'provider_payload', 'array'],
            ['SmsMessage', 'is_deleted', 'boolean'],
            ['SmsOrder', 'total_price', 'decimal:2'],
            ['Transaction', 'regulated_details', 'array'],
            ['Transaction', 'voided_at', 'datetime'],
            ['TransactionAttachment', 'metadata', 'array'],
            ['User', 'notify_low_stock', 'boolean'],
            ['UserNotification', 'meta', 'array'],
            ['UserNotification', 'read_at', 'datetime'],
        ];
    }

    #[DataProvider('casts')]
    public function test_sensitive_model_values_have_explicit_casts(
        string $name,
        string $attribute,
        string $cast
    ): void {
        $class = "App\\Models\\v1\\{$name}";
        $this->assertSame($cast, (new $class())->getCasts()[$attribute] ?? null);
    }

    public function test_authentication_models_hide_credentials(): void
    {
        foreach (['User', 'SuperAdmin'] as $name) {
            $class = "App\\Models\\v1\\{$name}";
            $this->assertContains('password', (new $class())->getHidden());
            $this->assertContains('remember_token', (new $class())->getHidden());
        }
    }
}
