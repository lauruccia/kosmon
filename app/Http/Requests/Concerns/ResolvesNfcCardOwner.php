<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Gestisce il campo "participant" delle card NFC nel formato
 * "company:ID" oppure "user:ID", validandolo e risolvendolo
 * nelle colonne company_id / owner_user_id.
 */
trait ResolvesNfcCardOwner
{
    protected function ownerRules(): array
    {
        return [
            'participant'      => ['required', 'string', 'regex:/^(company|user):\d+$/'],
            'participant_type' => ['required', 'in:company,user'],
            'participant_id'   => [
                'required',
                'integer',
                Rule::exists($this->participantTable(), 'id'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $participant = (string) $this->input('participant', '');

        if (str_contains($participant, ':')) {
            [$type, $id] = explode(':', $participant, 2);
            $this->merge([
                'participant_type' => $type,
                'participant_id'   => (int) $id,
            ]);
        }
    }

    private function participantTable(): string
    {
        return $this->input('participant_type') === 'user' ? 'users' : 'companies';
    }

    /**
     * Restituisce le colonne titolare risolte per il salvataggio.
     *
     * @return array{company_id: int|null, owner_user_id: int|null}
     */
    public function resolvedOwner(): array
    {
        $type = $this->input('participant_type');
        $id   = (int) $this->input('participant_id');

        return $type === 'user'
            ? ['company_id' => null, 'owner_user_id' => $id]
            : ['company_id' => $id, 'owner_user_id' => null];
    }
}
