<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulated_customers', function (Blueprint $table) {
            $table->bigIncrements('regulated_customer_id');
            $table->string('full_name');
            $table->string('contact_number', 30)->nullable();
            $table->string('id_number', 120)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('barangay', 120)->nullable();
            $table->string('city_municipality', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 80)->default('Philippines');
            $table->string('formatted_address', 500)->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('regulated_customer_id')->nullable()->after('documents_submitted');
            $table->string('customer_contact_number', 30)->nullable()->after('regulated_customer_id');
            $table->string('customer_id_number', 120)->nullable()->after('customer_contact_number');
            $table->string('customer_address_line', 255)->nullable()->after('customer_id_number');
            $table->string('customer_barangay', 120)->nullable()->after('customer_address_line');
            $table->string('customer_city_municipality', 120)->nullable()->after('customer_barangay');
            $table->string('customer_province', 120)->nullable()->after('customer_city_municipality');
            $table->string('customer_postal_code', 20)->nullable()->after('customer_province');
            $table->string('customer_country', 80)->nullable()->after('customer_postal_code');
            $table->string('customer_formatted_address', 500)->nullable()->after('customer_country');
            $table->string('regulated_classification', 50)->nullable()->after('customer_formatted_address');

            $table->foreign('regulated_customer_id')
                ->references('regulated_customer_id')
                ->on('regulated_customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['regulated_customer_id']);
            $table->dropColumn([
                'regulated_customer_id',
                'customer_contact_number',
                'customer_id_number',
                'customer_address_line',
                'customer_barangay',
                'customer_city_municipality',
                'customer_province',
                'customer_postal_code',
                'customer_country',
                'customer_formatted_address',
                'regulated_classification',
            ]);
        });

        Schema::dropIfExists('regulated_customers');
    }
};
