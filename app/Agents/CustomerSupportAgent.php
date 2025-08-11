<?php

namespace App\Agents;

use App\Tools\MenuTool;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class CustomerSupportAgent
{
    protected string $name = 'customer_support';

    protected string $description = 'Customer support agent untuk restoran dengan akses data menu';

    protected ?Provider $provider = Provider::Gemini;

    protected string $model = 'gemini-2.0-flash';

    protected MenuTool $menuTool;

    public function __construct()
    {
        $this->menuTool = new MenuTool;
    }

    public function processMessage(array $message, array $context = []): string
    {
        try {
            // Ambil konten dari pesan
            $userMessage = $message['content'] ?? '';

            // Cek apakah pesan berkaitan dengan menu
            if ($this->isMenuRelated($userMessage)) {
                // Ekstrak parameter dari pesan
                $params = $this->extractMenuParameters($userMessage);

                // Gunakan MenuTool untuk mendapatkan data
                $menuData = $this->menuTool->handle($params);

                // Format response dengan data menu
                return $this->formatMenuResponse($userMessage, $menuData);
            }

            // Jika tidak berkaitan dengan menu, gunakan LLM untuk response umum
            $prompt = $this->buildPrompt($userMessage, $context);

            $response = Prism::text()
                ->using($this->provider, $this->model)
                ->withPrompt($prompt)
                ->generate();

            return $response->text;

        } catch (\Exception $e) {
            return 'Maaf, terjadi kesalahan saat memproses pesan Anda. Silakan coba lagi.';
        }
    }

    private function isMenuRelated(string $message): bool
    {
        $keywords = ['menu', 'makanan', 'minuman', 'harga', 'restoran', 'pesan', 'order', 'food', 'drink', 'price'];
        $message = strtolower($message);

        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractMenuParameters(string $message): array
    {
        $params = [];

        // Ekstrak kategori
        if (strpos($message, 'makanan') !== false || strpos($message, 'food') !== false) {
            $params['category'] = 'makanan';
        } elseif (strpos($message, 'minuman') !== false || strpos($message, 'drink') !== false) {
            $params['category'] = 'minuman';
        } elseif (strpos($message, 'dessert') !== false) {
            $params['category'] = 'dessert';
        }

        // Ekstrak harga
        if (preg_match('/(?:dibawah|under|kurang dari)\s+(\d+)/i', $message, $matches)) {
            $params['price_range'] = ['max' => (int) $matches[1]];
        }

        if (preg_match('/(?:diatas|above|lebih dari)\s+(\d+)/i', $message, $matches)) {
            $params['price_range'] = array_merge($params['price_range'] ?? [], ['min' => (int) $matches[1]]);
        }

        // Ekstrak search term
        $searchTerms = ['makanan', 'minuman', 'dessert', 'harga', 'menu'];
        $cleanMessage = str_ireplace($searchTerms, '', $message);
        $cleanMessage = trim($cleanMessage);

        if (! empty($cleanMessage) && strlen($cleanMessage) > 2) {
            $params['search'] = $cleanMessage;
        }

        $params['limit'] = 10;

        return $params;
    }

    private function formatMenuResponse(string $question, string $menuData): string
    {
        $data = json_decode($menuData, true);

        if ($data['status'] === 'error') {
            return 'Maaf, terjadi kesalahan saat mengambil data menu: '.$data['message'];
        }

        if ($data['status'] === 'not_found') {
            return 'Maaf, tidak ada menu yang sesuai dengan kriteria yang Anda cari. Silakan coba dengan kata kunci lain.';
        }

        if (empty($data['data'])) {
            return 'Maaf, tidak ada menu yang tersedia saat ini.';
        }

        $response = "Berikut adalah menu yang tersedia sesuai dengan permintaan Anda:\n\n";

        foreach ($data['data'] as $item) {
            $name = $item['name'] ?? 'Nama tidak tersedia';
            $category = $item['category'] ?? 'Kategori tidak tersedia';
            $price = $item['price'] ?? 0;
            $description = $item['description'] ?? 'Deskripsi tidak tersedia';

            $response .= "**{$name}**\n";
            $response .= "- Kategori: {$category}\n";
            $response .= '- Harga: Rp '.number_format($price, 0, ',', '.')."\n";
            $response .= "- Deskripsi: {$description}\n\n";
        }

        return $response;
    }

    private function buildPrompt(string $userMessage, array $context): string
    {
        return "Anda adalah customer support untuk restoran. Jawab pertanyaan pengguna dengan ramah dan informatif.

Pertanyaan: {$userMessage}

Jawaban:";
    }

    /*

    Optional hook methods to override:

    public function beforeLlmCall(array $inputMessages, AgentContext $context): array
    {
        // $context->setState('custom_data_for_llm', 'some_value');
        // $inputMessages[] = ['role' => 'system', 'content' => 'Additional system note for this call.'];
        return parent::beforeLlmCall($inputMessages, $context);
    }

    public function afterLlmResponse(mixed $response, AgentContext $context): mixed {

         return parent::afterLlmResponse($response, $context);

    }

    public function beforeToolCall(string $toolName, array $arguments, AgentContext $context): array {

        return parent::beforeToolCall($toolName, $arguments, $context);

    }

    public function afterToolResult(string $toolName, string $result, AgentContext $context): string {

        return parent::afterToolResult($toolName, $result, $context);

    } */
}
