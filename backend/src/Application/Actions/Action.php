<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\Actions\Validation\RequestValidator;
use App\Application\Services\Exception\ValidationException;
use App\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class Action
{
    private const MAX_PAGE_SIZE = 100;
    public const MAX_PAGE = 1_000_000;

    protected LoggerInterface $logger;

    protected Request $request;

    protected Response $response;

    protected array $args;

    private RequestValidator $validator;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->validator = new RequestValidator();
    }

    /**
     * @throws HttpNotFoundException
     * @throws HttpBadRequestException
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;

        try {
            return $this->action();
        } catch (DomainRecordNotFoundException $e) {
            throw new HttpNotFoundException($this->request, $e->getMessage());
        }
    }

    /**
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    abstract protected function action(): Response;

    /**
     * @return array|object
     */
    protected function getFormData()
    {
        return $this->request->getParsedBody();
    }

    /**
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function resolveArg(string $name)
    {
        if (!isset($this->args[$name])) {
            throw new HttpBadRequestException($this->request, "Could not resolve argument `{$name}`.");
        }

        return $this->args[$name];
    }

    /**
     * The parsed JSON request body, guaranteed to be an array.
     *
     * @throws ValidationException when the body is missing, malformed, or
     *     not a JSON object/array
     */
    protected function getJsonBody(): array
    {
        $body = $this->request->getParsedBody();

        if (!is_array($body)) {
            throw new ValidationException([]);
        }

        return $body;
    }

    /**
     * A positive-int route argument, e.g. the `{id}` in `/products/{id}`.
     * Route args always arrive as strings.
     *
     * @throws ValidationException when the arg is not a positive integer
     */
    protected function getIntArg(string $name): int
    {
        $raw = $this->resolveArg($name);
        $raw = is_scalar($raw) ? (string) $raw : '';

        $this->validator->validate([$name => $raw], [$name => v::digit()->notEmpty()]);

        $value = (int) $raw;
        if ($value < 1) {
            throw new ValidationException([$name => 'must be a positive integer']);
        }

        return $value;
    }

    /**
     * The `page` query param: a positive int capped at MAX_PAGE, defaulting to
     * 1 when absent.
     *
     * @throws ValidationException
     */
    protected function getPage(): int
    {
        return $this->getPositiveIntQueryParam('page', 1, self::MAX_PAGE);
    }

    /**
     * The `size` query param: a positive int capped at 100 (matching the
     * service's pagination clamp), defaulting to 20 when absent.
     *
     * @throws ValidationException
     */
    protected function getSize(): int
    {
        return $this->getPositiveIntQueryParam('size', 20, self::MAX_PAGE_SIZE);
    }

    /**
     * The optional `q` query param: a string, max 255 characters. Absent,
     * blank or whitespace-only resolves to null.
     *
     * @throws ValidationException
     */
    protected function getOptionalSearchTerm(): ?string
    {
        $raw = $this->request->getQueryParams()['q'] ?? null;

        $this->validator->validate(
            ['q' => $raw],
            ['q' => v::optional(v::stringType()->length(null, 255))],
        );

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * The optional `category` query param: a string, max 100 characters.
     * Absent, blank or whitespace-only resolves to null.
     *
     * @throws ValidationException
     */
    protected function getCategory(): ?string
    {
        $raw = $this->request->getQueryParams()['category'] ?? null;

        $this->validator->validate(
            ['category' => $raw],
            ['category' => v::optional(v::stringType()->length(null, 100))],
        );

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * @throws ValidationException
     */
    private function getPositiveIntQueryParam(string $name, int $default, ?int $max): int
    {
        $raw = $this->request->getQueryParams()[$name] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        $raw = is_scalar($raw) ? (string) $raw : '';
        $this->validator->validate([$name => $raw], [$name => v::digit()->notEmpty()]);

        // A digit string longer than an int would silently saturate on cast, so
        // treat it as out of range explicitly.
        $digits = ltrim($raw, '0');
        $value = strlen($digits) > strlen((string) PHP_INT_MAX) ? PHP_INT_MAX : (int) $digits;
        $overflowed = $value === PHP_INT_MAX;
        if ($value < 1 || $overflowed || ($max !== null && $value > $max)) {
            $message = $max !== null ? "must be between 1 and {$max}" : 'must be a positive integer';
            throw new ValidationException([$name => $message]);
        }

        return $value;
    }

    /**
     * @param array|object|null $data
     */
    protected function respondWithData($data = null, int $statusCode = 200): Response
    {
        $payload = new ActionPayload($statusCode, $data);

        return $this->respond($payload);
    }

    /**
     * Like respondWithData(), but also sets `meta.page`, `meta.size` and
     * `meta.total`, for paginated list/search endpoints.
     *
     * @param array|object|null $data
     */
    protected function respondWithPage($data, int $page, int $size, int $total, int $statusCode = 200): Response
    {
        $payload = new ActionPayload($statusCode, $data, null, ['page' => $page, 'size' => $size, 'total' => $total]);

        return $this->respond($payload);
    }

    protected function respond(ActionPayload $payload): Response
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        $this->response->getBody()->write($json);

        return $this->response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus($payload->getStatusCode());
    }
}
