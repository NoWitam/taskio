<?php

namespace App\Modules\Generator\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use App\Modules\Generator\Models\GenerationSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Promotes a session's PRODUCED image onto the user's Disk ("Zapisz na Dysk") — the DURABLE export of a
 * run's output. Extracted VERBATIM from {@see \App\Modules\Generator\Http\Controllers\GenerationSessionController::saveToDisk}
 * (which now delegates here, no behavior change) so a NON-HTTP caller — an automated run that must keep the
 * images it just produced, since the session's own blobs are transient and the lifecycle reaper purges them
 * — can export without going through a controller.
 *
 * The composition is unchanged: the part's CURRENT version via {@see GenerationSessionRefiner::currentImageVersion},
 * the bytes via {@see GeneratedImageStore::get}, and the create through Disk's own
 * {@see FileService::storeDiskContent} (no hand-rolled storage), which resolves the optional `folder_id`
 * with its OWN tenant-scoped lookup (a foreign id 404s, null = the Disk root) and stamps the workspace +
 * creator. The Generator → Disk edge is the deliberate, documented one (ADR-0034 / the boundary test).
 *
 * AUTHORIZATION IS THE CALLER'S: the HTTP path is double-authorized in
 * {@see \App\Modules\Generator\Http\Requests\SaveGeneratedImageRequest} (session `update` + Disk `create`).
 * This service performs no policy check — it is reached only from an already-authorized request or from a
 * server-side run acting on its OWN session.
 *
 * TWO ENTRY POINTS, ONE COMPOSITION: {@see saveToDisk} keeps the HTTP contract (a part with NO produced
 * image — failed / never run / undone — aborts 404, the exact current behavior), while
 * {@see saveToDiskIfPresent} returns NULL for that same case. A queued caller has no HTTP response to abort
 * into: "there is nothing to export" is an ORDINARY outcome for it, not a 404. The aborting one is written
 * in terms of the non-aborting one, so the export itself (version → bytes → Disk create) exists once.
 */
class GeneratedImageExporter
{
    public function __construct(
        private GenerationSessionRefiner $refiner,
        private GeneratedImageStore $images,
        private FileService $files,
    ) {}

    /**
     * The HTTP entry point: write the CURRENT produced image of $partKey into the active workspace's Disk and
     * return the new File, ABORTING 404 when the part produced none.
     *
     * $partKey may be a TOP-LEVEL image part (`image`) or a NESTED per-item one (`storyboard.<i>` /
     * `scene_plan.<i>`) — the refiner resolves both, so the caller simply passes whatever key the run's
     * results carry. A blank/absent $name falls back to the localized default; a blank/absent $folderId
     * places the file at the Disk root.
     */
    public function saveToDisk(GenerationSession $session, string $partKey, ?string $name = null, ?string $folderId = null): File
    {
        $file = $this->saveToDiskIfPresent($session, $partKey, $name, $folderId);

        abort_if($file === null, Response::HTTP_NOT_FOUND);

        return $file;
    }

    /**
     * The NON-HTTP entry point: identical export, but returns NULL instead of aborting when $partKey has no
     * current produced image (a failed / never-run / undone part). A server-side run treats that as an
     * ordinary "nothing to export" and keeps going; only the HTTP path turns it into a 404
     * ({@see saveToDisk}). Everything else — the version resolution, the bytes, the name fallback, the folder
     * resolution and the Disk create — is this one method, shared by both.
     */
    public function saveToDiskIfPresent(GenerationSession $session, string $partKey, ?string $name = null, ?string $folderId = null): ?File
    {
        $version = $this->refiner->currentImageVersion($session, $partKey);

        $bytes = $version === null ? null : $this->images->get($session->id, $partKey, $version);

        if ($bytes === null) {
            return null;
        }

        $name = trim((string) $name);
        if ($name === '') {
            $name = __('generator.sessions.saved_image_name') . '.png';
        }

        return $this->files->storeDiskContent($bytes, $name, 'image/png', ($folderId ?? '') !== '' ? $folderId : null);
    }

    /**
     * EVERY part key of $session that currently carries a produced image, in results order — top-level image
     * parts (`image`) and nested per-item ones (`storyboard.<i>` / `scene_plan.<i>`) alike, in exactly the
     * addressing {@see saveToDiskIfPresent} accepts.
     *
     * WHY IT LIVES HERE. A server-side run must export the WHOLE run's images, not one part the user clicked,
     * so it needs the LIST before it can call the export. The per-kind shape knowledge (which result kinds own
     * images, and under which nested list) is the Generator's, so the enumeration stays in this module and
     * delegates to the SINGLE existing authority — {@see GenerationSessionRefiner::blobRefsOf}, the same walk
     * the blob GC uses. A caller therefore never has to read `results` itself.
     *
     * A PURE read: no query, no write, never throws (a malformed/legacy result contributes nothing). Callers
     * still export through {@see saveToDiskIfPresent}, which re-resolves the CURRENT version per key.
     *
     * @return array<int, string>
     */
    public function producedImagePartKeys(GenerationSession $session): array
    {
        $results = is_array($session->results) ? $session->results : [];
        $keys = [];

        foreach ($results as $partKey => $result) {
            if (!is_array($result)) {
                continue;
            }

            foreach ($this->refiner->blobRefsOf((string) $partKey, $result) as $ref) {
                $keys[] = $ref['partKey'];
            }
        }

        return array_values(array_unique($keys));
    }
}
