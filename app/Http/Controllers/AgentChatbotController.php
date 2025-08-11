<?php

namespace App\Http\Controllers;

use App\Agents\CustomerSupportAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AgentChatbotController extends Controller
{
    public function index(): Response
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

        try {
            // Inisialisasi Customer Support Agent
            $agent = new CustomerSupportAgent;

            // Buat konteks untuk agent
            $context = [
                'user_message' => $question,
                'timestamp' => now()->toDateTimeString(),
            ];

            if ($wantsStream) {
                return response()->stream(
                    function () use ($agent, $question, $context) {
                        // Gunakan agent untuk mendapatkan response
                        $response = $agent->processMessage([
                            'role' => 'user',
                            'content' => $question,
                        ], $context);

                        // Pastikan encoding UTF-8
                        $text = trim($response);
                        if (! mb_check_encoding($text, 'UTF-8')) {
                            $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
                        }

                        // Stream response
                        $chunks = preg_split('/(?<!\p{M})(?=\p{M}|\X)/u', $text);

                        foreach ($chunks as $chunk) {
                            echo $chunk;
                            ob_flush();
                            flush();
                            usleep(10000);
                        }
                    },
                    200,
                    [
                        'Cache-Control' => 'no-cache',
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'X-Accel-Buffering' => 'no',
                    ]
                );
            }

            // Response non-streaming
            $response = $agent->processMessage([
                'role' => 'user',
                'content' => $question,
            ], $context);

            // Pastikan encoding UTF-8
            $answerText = trim($response);
            if (! mb_check_encoding($answerText, 'UTF-8')) {
                $answerText = mb_convert_encoding($answerText, 'UTF-8', mb_detect_encoding($answerText));
            }

            return response()->json([
                'question' => $question,
                'answer' => $answerText,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (Throwable $e) {
            Log::error('Agent Chatbot Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Maaf, terjadi kesalahan saat memproses pesan Anda. Silakan coba lagi.',
            ], 500);
        }
    }
}
