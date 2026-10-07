<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => Document::PENDING,
            'original_name' => 'receipt.png',
            'file_path' => 'documents/'.fake()->uuid().'.png',
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', fake()->uuid()),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
