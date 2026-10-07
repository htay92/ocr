<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    /**
     * Only the owner can see a document; for anyone else it does not exist.
     */
    public function view(User $user, Document $document): Response
    {
        return $document->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Document $document): Response
    {
        return $this->view($user, $document);
    }
}
