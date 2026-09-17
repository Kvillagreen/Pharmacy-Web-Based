<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateNumbers = DB::table('batches')
            ->select('batch_number')
            ->whereNotNull('batch_number')
            ->where('batch_number', '<>', '')
            ->groupBy('batch_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('batch_number');

        foreach ($duplicateNumbers as $batchNumber) {
            $batches = DB::table('batches')
                ->where('batch_number', $batchNumber)
                ->orderBy('batch_id')
                ->get(['batch_id', 'batch_number']);

            foreach ($batches->skip(1) as $batch) {
                $replacement = $this->uniqueReplacement((string) $batch->batch_number, (int) $batch->batch_id);

                DB::table('batches')->where('batch_id', $batch->batch_id)->update([
                    'batch_number' => $replacement,
                    'updated_at' => now(),
                ]);

                DB::table('transaction_items')->where('batch_id', $batch->batch_id)->update([
                    'batch_number' => $replacement,
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->unique('batch_number', 'batches_batch_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropUnique('batches_batch_number_unique');
        });
    }

    private function uniqueReplacement(string $original, int $batchId): string
    {
        $base = substr($original, 0, 235);
        $candidate = $base . '-B' . $batchId;
        $suffix = 1;

        while (DB::table('batches')->where('batch_number', $candidate)->exists()) {
            $candidate = $base . '-B' . $batchId . '-' . $suffix++;
        }

        return $candidate;
    }
};
