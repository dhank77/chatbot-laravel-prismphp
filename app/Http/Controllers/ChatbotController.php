<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

class ChatbotController extends Controller
{
    // Whitelist konfigurasi
    protected array $whitelistTables = [
        'menus' => ['id', 'name', 'description', 'category', 'price', 'order_count', 'created_at']
    ];

    protected int $maxLimit = 100;

    public function index() : Response 
    {
        return Inertia::render('chatbot/index');
    }

    public function chat(Request $request)
    {
        $question = trim($request->input('message', ''));

        if (empty($question)) {
            return response()->json(['error' => 'Pesan kosong'], 400);
        }
        
        // Cek apakah request menerima event-stream
        $wantsStream = $request->header('Accept') === 'text/event-stream';

        // 1) Minta LLM untuk mengembalikan **JSON terstruktur** (tidak SQL mentah)
        $systemPrompt = <<<PROMPT
Kamu adalah assistant yang mengubah pertanyaan bahasa manusia menjadi JSON terstruktur untuk query database MySQL.
Database hanya mengizinkan operasi SELECT. Output harus **HANYA** JSON valid (tidak ada teks lain).
Schema JSON yang diharapkan:
{
  "action": "select",
  "table": "menus",
  "columns": ["name","price"],            // optional, jika kosong artikan semua kolom whitelisted
  "filters": [                            // optional, array of conditions
     {"column":"category","op":"like","value":"%dingin%"},
     {"column":"price","op":"<=","value":30000}
  ],
  "order_by": [{"column":"order_count","direction":"desc"}], // optional
  "limit": 5                               // optional, integer
}
Aturan penting:
- Hanya table yang boleh digunakan adalah: menus
- Hanya kolom yang diizinkan: id,name,description,category,price,order_count,created_at
- Gunakan operator: =, !=, >, <, >=, <=, like, in
- Jangan pakai subqueries, JOIN, UNION, komentar, atau pernyataan non-SELECT.
- Jika user menanyakan "menu favorit" gunakan order_by order_count desc.
- Jika user menanyakan "menu terbaru" gunakan order_by created_at desc.
- Jika user tidak menspesifikkan columns, kembalikan columns yang aman (name, price, description, category).
- Output MUST be EXACTLY a JSON object (no explanation).
PROMPT;

        try {
            $jsonResponse = Prism::text()
                ->using(Provider::Gemini, 'gemini-2.0-flash')
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($question)
                ->asText();

            // Bersihkan kemungkinan whitespace
            $jsonResponse = trim($jsonResponse->text);

            // Jika model kadang meng-wrap JSON dalam markdown backticks, hapus
            $jsonResponse = $this->stripMarkdownCodeFence($jsonResponse);

            $structured = json_decode($jsonResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($structured)) {
                Log::warning('LLM JSON parse error', ['raw' => $jsonResponse]);
                return response()->json(['error' => 'Gagal memproses instruksi LLM (JSON invalid)'], 500);
            }

            // Validasi struktur
            $valid = $this->validateStructuredQuery($structured);
            if ($valid !== true) {
                return response()->json(['error' => 'Invalid structured query: '.$valid], 400);
            }

            // Bangun query menggunakan Query Builder (AMAN)
            $results = $this->executeStructuredQuery($structured);

        } catch (Throwable $e) {
            Log::error('Chatbot NL2STRUCT error', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Terjadi kesalahan internal'], 500);
        }

        // 2) Minta LLM buat jawaban user-friendly dari hasil query
        $answerPrompt = "
Pertanyaan pelanggan: {$question}

Data dari database (array JSON): " . json_encode($results) . "

Tolong jawab singkat, ramah, dan mudah dimengerti pelanggan (bahasa Indonesia). Jangan sertakan JSON, hanya jawaban teks.";
        $answer = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withSystemPrompt("Kamu adalah chatbot restoran yang menjawab pertanyaan pelanggan dengan bahasa santai dan jelas.")
            ->withPrompt($answerPrompt)
            ->asText();

        // Jika client meminta streaming response
        if ($wantsStream) {
            return response()->stream(
                function () use ($answer) {
                    // Ambil teks jawaban dan pastikan encoding UTF-8
                    $text = trim($answer->text);
                    
                    // Konversi ke UTF-8 jika belum UTF-8
                    if (!mb_check_encoding($text, 'UTF-8')) {
                        $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
                    }
                    
                    // Simulasi streaming karakter demi karakter
                    $chunks = preg_split('/(?<!\p{M})(?=\p{M}|\X)/u', $text);
                    
                    foreach ($chunks as $chunk) {
                        echo $chunk;
                        ob_flush();
                        flush();
                        // Tambahkan delay kecil untuk efek mengetik
                        usleep(10000); // 10ms delay
                    }
                },
                200,
                [
                    'Cache-Control' => 'no-cache',
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'X-Accel-Buffering' => 'no', // Untuk Nginx
                ]
            );
        }
        
        // Kembalikan hasil JSON jika tidak streaming
        // Pastikan encoding UTF-8 untuk respons JSON
        $answerText = trim($answer->text);
        if (!mb_check_encoding($answerText, 'UTF-8')) {
            $answerText = mb_convert_encoding($answerText, 'UTF-8', mb_detect_encoding($answerText));
        }
        
        return response()->json([
            'question' => $question,
            'structured_query' => $structured,
            'results' => $results,
            'answer' => $answerText,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    protected function stripMarkdownCodeFence(string $s): string
    {
        // hapus ```json ... ``` atau ``` ... ```
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/\s*```$/', '', $s);
        return trim($s);
    }

    protected function validateStructuredQuery(array $q)
    {
        // Basic checks
        if (!isset($q['action']) || strtolower($q['action']) !== 'select') {
            return 'Only select action allowed';
        }

        if (!isset($q['table'])) {
            return 'Missing table';
        }

        $table = strtolower($q['table']);
        if (!isset($this->whitelistTables[$table])) {
            return "Table '{$table}' not allowed";
        }

        $allowedCols = $this->whitelistTables[$table];
        // columns
        if (isset($q['columns'])) {
            if (!is_array($q['columns'])) return 'columns must be array';
            foreach ($q['columns'] as $col) {
                if (!in_array($col, $allowedCols)) return "column '{$col}' not allowed";
            }
        }

        // filters
        if (isset($q['filters'])) {
            if (!is_array($q['filters'])) return 'filters must be array';
            $allowedOps = ['=','!=','>','<','>=','<=','like','in'];
            foreach ($q['filters'] as $f) {
                if (!isset($f['column'], $f['op'], $f['value'])) return 'filter missing fields';
                if (!in_array($f['column'], $allowedCols)) return "filter column '{$f['column']}' not allowed";
                if (!in_array(strtolower($f['op']), $allowedOps)) return "filter op '{$f['op']}' not allowed";
            }
        }

        // order_by
        if (isset($q['order_by'])) {
            if (!is_array($q['order_by'])) return 'order_by must be array';
            foreach ($q['order_by'] as $o) {
                if (!isset($o['column'])) return 'order_by.column missing';
                if (!in_array($o['column'], $allowedCols)) return "order_by column '{$o['column']}' not allowed";
                $dir = strtolower($o['direction'] ?? 'asc');
                if (!in_array($dir, ['asc','desc'])) return 'order_by.direction invalid';
            }
        }

        // limit
        if (isset($q['limit'])) {
            if (!is_int($q['limit']) && !ctype_digit((string)$q['limit'])) return 'limit must be integer';
            $limit = (int)$q['limit'];
            if ($limit < 1 || $limit > $this->maxLimit) return "limit must be between 1 and {$this->maxLimit}";
        }

        return true;
    }

    protected function executeStructuredQuery(array $q)
    {
        $table = $q['table'];
        $cols = $q['columns'] ?? ['name','price','description','category'];

        $qb = DB::table($table)->select($cols);

        if (!empty($q['filters'])) {
            foreach ($q['filters'] as $f) {
                $col = $f['column'];
                $op = strtolower($f['op']);
                $val = $f['value'];

                if ($op === 'in' && is_array($val)) {
                    $qb->whereIn($col, $val);
                } elseif ($op === 'like') {
                    $qb->where($col, 'like', $val);
                } else {
                    // operator mapping safe
                    $allowed = ['=','!=','>','<','>=','<='];
                    if (!in_array($op, $allowed)) continue;
                    if ($op === '!=') {
                        $qb->where($col, '!=', $val);
                    } else {
                        $qb->where($col, $op, $val);
                    }
                }
            }
        }

        if (!empty($q['order_by'])) {
            foreach ($q['order_by'] as $o) {
                $dir = strtolower($o['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                $qb->orderBy($o['column'], $dir);
            }
        }

        if (!empty($q['limit'])) {
            $qb->limit((int)$q['limit']);
        } else {
            $qb->limit(10); // default
        }

        // Execute and return array
        return $qb->get()->map(function ($row) {
            return (array)$row;
        })->toArray();
    }
}
