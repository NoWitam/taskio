<?php

namespace App\Modules\Publishing\Exceptions;

use App\Modules\Publishing\Enums\PublicationStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A STATE MOVE THE MACHINE DOES NOT HAVE — thrown, never logged and swallowed.
 *
 * The alternative was considered and refused: a manager that returned false, or that quietly no-oped on
 * an illegal move, produces a system whose state is wrong and whose caller believes it succeeded. In a
 * module where "wrong state" can mean "we think this went out and it did not" — or, far worse, "we
 * think it did not and it did" — a silent write is the defect, not the exception.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE THREE REASONS ARE NOT DECORATION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * They exist because the three carry different ADVICE, and a caller (or a person reading a 422) needs
 * to be told which:
 *
 *   reconcile_before_retry  You tried to publish something sitting in `needs_reconcile`. There is no
 *                           such edge and there will not be one. Ask the platform first
 *                           (`PlatformAdapter::findExisting`); what it answers decides whether this
 *                           becomes `published` or `failed`, and only from `failed` is a retry a move
 *                           at all. This is the single most important refusal in the module.
 *
 *   blocked_holds           You tried to publish something on a connection that is held. The hold is
 *                           the feature: fix the connection and re-arm. Publishing from `blocked` would
 *                           be one failure per scheduled item, all at once, all with the same cause.
 *
 *   terminal                It is already out. Nothing this application writes can recall it, so the
 *                           row's record of it does not get rewritten.
 *
 *   not_allowed             Everything else the table does not contain.
 *
 * 422 with `{code, message, context}` — the module-wide refusal shape, the same one
 * `KnowledgeRelationRefused` uses, so a client points at a state rather than parsing a sentence.
 */
class PublicationTransitionRefused extends RuntimeException
{
    public const RECONCILE_BEFORE_RETRY = 'publication_reconcile_before_retry';

    public const BLOCKED_HOLDS = 'publication_blocked_holds';

    public const TERMINAL = 'publication_terminal';

    public const NOT_ALLOWED = 'publication_transition_not_allowed';

    /**
     * `$reason`, not `$code` — `Exception::$code` already exists and is an int, so a readonly string of
     * that name is a fatal redeclaration. The WIRE field stays `code`, which is the app's convention.
     */
    public function __construct(
        public readonly string $reason,
        public readonly PublicationStatus $from,
        public readonly PublicationStatus $to,
    ) {
        parent::__construct(__('publishing.transitions.' . $this->messageKey(), [
            'from' => $from->label(),
            'to' => $to->label(),
        ]));
    }

    /**
     * Which refusal a (from, to) pair is, decided in ONE place so the wording and the code cannot
     * disagree with the table that produced the refusal.
     *
     * The order of the arms is the order of severity, and it matters: a move out of `needs_reconcile`
     * is reported as the reconciliation rule even when the target also happens to be unreachable for
     * another reason, because that is the sentence the reader has to act on.
     */
    public static function for(PublicationStatus $from, PublicationStatus $to): self
    {
        $reason = match (true) {
            $from === PublicationStatus::NEEDS_RECONCILE && $to === PublicationStatus::PUBLISHING => self::RECONCILE_BEFORE_RETRY,
            $from === PublicationStatus::BLOCKED && $to === PublicationStatus::PUBLISHING => self::BLOCKED_HOLDS,
            $from->isTerminal() => self::TERMINAL,
            default => self::NOT_ALLOWED,
        };

        return new self($reason, $from, $to);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->reason,
            'message' => $this->getMessage(),
            'context' => [
                'from' => $this->from->value,
                'to' => $this->to->value,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function messageKey(): string
    {
        return match ($this->reason) {
            self::RECONCILE_BEFORE_RETRY => 'reconcile_before_retry',
            self::BLOCKED_HOLDS => 'blocked_holds',
            self::TERMINAL => 'terminal',
            default => 'not_allowed',
        };
    }
}
