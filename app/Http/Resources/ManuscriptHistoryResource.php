<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Manuscript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Manuscript */
class ManuscriptHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->period,
            'bill' => $this->bill,
            'total_arrears' => $this->total_arrears,
            'credit' => $this->credit,
            'total_bill' => $this->total_bill,
        ];
    }
}
