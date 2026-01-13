<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Http\Requests\IndexUsersRequest;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Repositories\UsersRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UsersController
{
    public function __construct(
        private readonly UsersRepository $repository
    ) {}

    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $ids = $request->array('ids');

        return UserResource::collection(
            empty($ids)
                ? $this->repository->cursorPaginate(
                    $request->string('q')
                )
                : $this->repository->getByIds($ids)
        );
    }
}
