<?php

declare(strict_types=1);

namespace App\Controller;

use App\Shared\Http\RequestInterface;
use App\Shared\Http\ResponseInterface;

abstract class AbstractController
{
    abstract protected function invoke(RequestInterface $request, ResponseInterface $response): ResponseInterface;

    public function __invoke(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->invoke($request, $response);
    }

    /** @param array<string, mixed> $data */
    protected function jsonResponse(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        return $response->withStatus($status)->withJson(['data' => $data]);
    }

    /** @param array<string, string> $fields */
    protected function errorResponse(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        array $fields = [],
    ): ResponseInterface {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return $response->withStatus($status)->withJson(['error' => $error]);
    }
}
