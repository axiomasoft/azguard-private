<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    public function toArray($request): array { return ['id' => $this->id, 'email' => $this->email]; }
}
