<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('news')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('news')
                ->orderBy('id')
                ->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('news')
                            ->where('id', $row->id)
                            ->update(['body' => $this->plainTextToHtml((string) $row->body)]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Rich HTML cannot be converted back to the original plain text without data loss.
    }

    private function plainTextToHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [$text];
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $lines = explode("\n", trim($paragraph));
            $escapedLines = array_map(
                static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $lines
            );
            $html[] = '<p>'.implode('<br>', $escapedLines).'</p>';
        }

        return implode("\n", $html);
    }
};
