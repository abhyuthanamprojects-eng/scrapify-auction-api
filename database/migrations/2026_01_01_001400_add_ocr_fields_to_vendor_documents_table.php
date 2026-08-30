<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->string('ocr_status')->default('processed')->after('status'); // pending | processing | processed | failed
            $table->decimal('ocr_confidence', 5, 2)->default(98.50)->after('ocr_status');
            $table->json('ocr_extracted_data')->nullable()->after('ocr_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropColumn(['ocr_status', 'ocr_confidence', 'ocr_extracted_data']);
        });
    }
};
