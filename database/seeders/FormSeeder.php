<?php

namespace Database\Seeders;

use App\Enums\IconEnum;
use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormContentVersion;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Seeder;

class FormSeeder extends Seeder
{
    /**
     * Seed an enabled "Ocena onboardingu" form with a v1 content version and
     * sample submissions spread randomly-but-evenly over the last 30 days.
     */
    public function run(): void
    {
        $workspace = Workspace::query()->orderBy('created_at')->firstOrFail();
        $users = User::query()->orderBy('created_at')->get();

        $enabledAt = now()->subDays(35);

        $form = new Form([
            'name' => 'Ocena onboardingu',
            'icon' => IconEnum::CLIPBOARD,
            'description' => 'Ankieta oceny procesu onboardingu wypełniana po pierwszych 30 dniach pracy.',
            'content' => $this->content(),
            'is_anonymous' => false,
            'enabled_at' => $enabledAt,
            'content_version' => 1,
            'content_updated_at' => $enabledAt,
            'creator_id' => $users->first()->id,
        ]);
        $form->workspace_id = $workspace->id;
        $form->created_at = $enabledAt->copy()->subDay();
        $form->save();

        $contentVersion = FormContentVersion::create([
            'form_id' => $form->id,
            'version' => 1,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
            'created_at' => $enabledAt,
        ]);

        $entries = require __DIR__ . '/data/onboarding_form_submissions.php';
        shuffle($entries);

        // One submission per equal slot of the 30-day window, at a random moment
        // inside its slot — random spacing with an even monthly spread.
        $windowStart = now()->subDays(30)->startOfDay();
        $slotMinutes = intdiv(30 * 24 * 60, count($entries));

        foreach ($entries as $index => $data) {
            $submittedAt = $windowStart->copy()->addMinutes(
                $index * $slotMinutes + random_int(0, $slotMinutes - 1)
            );

            $submission = new FormSubmission([
                'form_id' => $form->id,
                // Manual fill-ins point back at the form itself and are approved immediately.
                'submittable_type' => $form->getMorphClass(),
                'submittable_id' => $form->id,
                'data' => $data,
                'form_content_version_id' => $contentVersion->id,
                'approved_at' => $submittedAt,
                'creator_id' => $users->random()->id,
            ]);
            $submission->workspace_id = $workspace->id;
            $submission->created_at = $submittedAt;
            $submission->updated_at = $submittedAt;
            $submission->save();
        }
    }

    /**
     * Form content whose element ids (and normalized labels) produce the target
     * JSON schema: dane_pracownika / ocena_onboardingu / feedback_onboardingu.
     */
    private function content(): array
    {
        return [
            [
                'id' => 'dane_pracownika',
                'type' => 'section',
                'config' => [
                    'name' => 'Dane pracownika',
                    'children' => [
                        [
                            'id' => 'imie_i_nazwisko',
                            'type' => 'short_text',
                            'config' => ['label' => 'Imię i nazwisko', 'required' => true],
                        ],
                        [
                            'id' => 'wiek',
                            'type' => 'number',
                            'config' => ['label' => 'Wiek', 'step' => 1, 'required' => true],
                        ],
                        [
                            'id' => 'stanowisko',
                            'type' => 'short_text',
                            'config' => ['label' => 'Stanowisko', 'required' => true],
                        ],
                        [
                            'id' => 'dzial',
                            'type' => 'select',
                            'config' => [
                                'label' => 'Dział',
                                'multiple' => true,
                                'required' => true,
                                'options' => [
                                    ['value' => 'it', 'label' => 'IT'],
                                    ['value' => 'hr', 'label' => 'HR'],
                                    ['value' => 'sprzedaz', 'label' => 'Sprzedaż'],
                                    ['value' => 'marketing', 'label' => 'Marketing'],
                                    ['value' => 'zespol_relacji_z_klientami', 'label' => 'Zespół relacji z klientami'],
                                ],
                            ],
                        ],
                        [
                            'id' => 'tryb_pracy',
                            'type' => 'select',
                            'config' => [
                                'label' => 'Tryb pracy',
                                'required' => true,
                                'options' => [
                                    ['value' => 'stacjonarnie', 'label' => 'Stacjonarnie'],
                                    ['value' => 'zdalnie', 'label' => 'Zdalnie'],
                                    ['value' => 'hybryda', 'label' => 'Hybryda'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'ocena_onboardingu',
                'type' => 'section',
                'config' => [
                    'name' => 'Ocena onboardingu',
                    'children' => [
                        $this->ratingField('ocena_wsparcia_managera', 'Ocena wsparcia managera'),
                        $this->ratingField('ocena_wsparcia_zespolu', 'Ocena wsparcia zespołu'),
                        $this->ratingField('ocena_przygotowania_dostepow_i_narzedzi', 'Ocena przygotowania dostępów i narzędzi'),
                        $this->ratingField('jasnosc_oczekiwan_dotyczacych_roli', 'Jasność oczekiwań dotyczących roli'),
                        [
                            'id' => 'najwieksze_trudnosci',
                            'type' => 'checklist',
                            'config' => [
                                'label' => 'Największe trudności',
                                'required' => true,
                                'options' => [
                                    ['value' => 'brak_dostepu_do_narzedzi', 'label' => 'Brak dostępu do narzędzi'],
                                    ['value' => 'niejasne_obowiazki', 'label' => 'Niejasne obowiązki'],
                                    ['value' => 'za_duzo_informacji_naraz', 'label' => 'Za dużo informacji naraz'],
                                    ['value' => 'brak_dokumentacji', 'label' => 'Brak dokumentacji'],
                                    ['value' => 'problemy_techniczne', 'label' => 'Problemy techniczne'],
                                ],
                            ],
                        ],
                        $this->ratingField(
                            'na_ile_pewnie_czujesz_sie_w_swojej_roli_po30_dniach',
                            'Na ile pewnie czujesz się w swojej roli po 30 dniach'
                        ),
                        [
                            'id' => 'adekwatnosc_tempa_wdrozenia',
                            'type' => 'select',
                            'config' => [
                                'label' => 'Adekwatność tempa wdrożenia',
                                'required' => true,
                                'options' => [
                                    ['value' => 'za_wolno', 'label' => 'Za wolno'],
                                    ['value' => 'w_sam_raz', 'label' => 'W sam raz'],
                                    ['value' => 'za_szybko', 'label' => 'Za szybko'],
                                ],
                            ],
                        ],
                        [
                            'id' => 'czy_polecilabys_ten_onboarding_innym',
                            'type' => 'select',
                            'config' => [
                                'label' => 'Czy poleciłabyś ten onboarding innym',
                                'required' => true,
                                'options' => [
                                    ['value' => 'tak', 'label' => 'Tak'],
                                    ['value' => 'nie', 'label' => 'Nie'],
                                    ['value' => 'nie_mam_zdania', 'label' => 'Nie mam zdania'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'feedback_onboardingu',
                'type' => 'section',
                'config' => [
                    'name' => 'Feedback onboardingu',
                    'children' => [
                        [
                            'id' => 'co_bylo_najbardziej_pomocne',
                            'type' => 'long_text',
                            'config' => ['label' => 'Co było najbardziej pomocne'],
                        ],
                        [
                            'id' => 'czego_zabraklo_w_onboardingu',
                            'type' => 'long_text',
                            'config' => ['label' => 'Czego zabrakło w onboardingu'],
                        ],
                        [
                            'id' => 'sugestie_usprawnien',
                            'type' => 'long_text',
                            'config' => ['label' => 'Sugestie usprawnień'],
                        ],
                        [
                            'id' => 'czy_sa_jakies_sygnaly_ryzyka_wymagajace_reakcji',
                            'type' => 'long_text',
                            'config' => ['label' => 'Czy są jakieś sygnały ryzyka wymagające reakcji'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function ratingField(string $id, string $label): array
    {
        return [
            'id' => $id,
            'type' => 'number',
            'config' => ['label' => $label, 'min' => 1, 'max' => 5, 'step' => 1, 'required' => true],
        ];
    }
}
