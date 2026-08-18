<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ProposalStatusRequest extends FormRequest
{
    /**
     * Authorization is enforced by the controller (role + job/proposal ownership).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Proposal status actions (shortlist / accept / reject / withdraw) require no payload.
     */
    public function rules(): array
    {
        return [];
    }
}
