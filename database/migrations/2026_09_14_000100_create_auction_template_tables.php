<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('template_code');
            $table->string('name');
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('direction')->default('both'); // forward | reverse | both
            $table->string('version', 20)->default('1.0');
            $table->string('status')->default('draft'); // draft | active | deprecated | retired
            $table->json('schema_definition');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('max_rows')->default(1000);
            $table->unsignedBigInteger('max_file_size')->default(10485760); // 10MB
            $table->boolean('template_required')->default(true);
            $table->boolean('allow_manual_items')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->unique(['template_code', 'version']);
        });

        Schema::create('auction_template_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('auction_templates')->cascadeOnDelete();
            $table->string('template_version', 20);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('disk')->default('local');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 64); // SHA-256
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('total_reference_value', 15, 2)->default(0);
            $table->string('status')->default('uploaded'); // uploaded | validating | valid | invalid | parsed | failed
            $table->json('validation_errors')->nullable();
            $table->json('parsed_summary')->nullable();
            $table->unsignedSmallInteger('submission_version')->default(1);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['auction_id', 'submission_version']);
        });

        Schema::create('auction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('auction_template_uploads')->nullOnDelete();
            $table->unsignedSmallInteger('row_number')->default(0);
            $table->string('item_name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4)->default(1);
            $table->string('unit', 20)->default('PCS');
            $table->string('condition')->nullable();
            $table->string('functional_status')->nullable();
            $table->string('physical_condition')->nullable();
            $table->unsignedSmallInteger('manufacturing_year')->nullable();
            $table->string('location')->nullable();
            $table->decimal('reference_value', 15, 2)->nullable();
            $table->decimal('reserve_value', 15, 2)->nullable();
            $table->json('attributes')->nullable();
            $table->string('serial_identifier')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('auction_id');
            $table->index('lot_id');
        });

        Schema::table('auctions', function (Blueprint $table) {
            if (! Schema::hasColumn('auctions', 'template_id')) {
                $table->foreignId('template_id')->nullable()->after('category_id')
                    ->constrained('auction_templates')->nullOnDelete();
            }
            if (! Schema::hasColumn('auctions', 'template_version')) {
                $table->string('template_version', 20)->nullable()->after('template_id');
            }
            if (! Schema::hasColumn('auctions', 'submission_version')) {
                $table->unsignedSmallInteger('submission_version')->default(0)->after('status');
            }
            if (! Schema::hasColumn('auctions', 'subcategory_id')) {
                $table->foreignId('subcategory_id')->nullable()->after('category_id')
                    ->constrained('categories')->nullOnDelete();
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('lots', 'brand')) {
                $table->string('brand')->nullable()->after('description');
            }
            if (! Schema::hasColumn('lots', 'model')) {
                $table->string('model')->nullable()->after('brand');
            }
            if (! Schema::hasColumn('lots', 'condition')) {
                $table->string('condition')->nullable()->after('uom');
            }
            if (! Schema::hasColumn('lots', 'location')) {
                $table->string('location')->nullable()->after('condition');
            }
            if (! Schema::hasColumn('lots', 'reference_value')) {
                $table->decimal('reference_value', 15, 2)->nullable()->after('location');
            }
            if (! Schema::hasColumn('lots', 'attributes')) {
                $table->json('attributes')->nullable()->after('reference_value');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'direction')) {
                $table->string('direction')->default('both')->after('is_active'); // forward | reverse | both
            }
            if (! Schema::hasColumn('categories', 'template_required')) {
                $table->boolean('template_required')->default(false)->after('direction');
            }
            if (! Schema::hasColumn('categories', 'allow_manual_items')) {
                $table->boolean('allow_manual_items')->default(true)->after('template_required');
            }
            if (! Schema::hasColumn('categories', 'allow_excel')) {
                $table->boolean('allow_excel')->default(true)->after('allow_manual_items');
            }
            if (! Schema::hasColumn('categories', 'max_rows')) {
                $table->unsignedInteger('max_rows')->default(1000)->after('allow_excel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $cols = ['direction', 'template_required', 'allow_manual_items', 'allow_excel', 'max_rows'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            $cols = ['description', 'brand', 'model', 'condition', 'location', 'reference_value', 'attributes'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('lots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('auctions', function (Blueprint $table) {
            $cols = ['template_id', 'template_version', 'submission_version', 'subcategory_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('auctions', $col)) {
                    if (in_array($col, ['template_id', 'subcategory_id'])) {
                        $table->dropConstrainedForeignId($col);
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });

        Schema::dropIfExists('auction_items');
        Schema::dropIfExists('auction_template_uploads');
        Schema::dropIfExists('auction_templates');
    }
};
