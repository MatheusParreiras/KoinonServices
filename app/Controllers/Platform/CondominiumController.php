<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Pagination;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Condominium;
use App\Services\BusinessRuleException;
use App\Services\CondominiumService;
use App\Services\InvitationService;
use DateTimeZone;

/**
 * Tenant management by the Super Admin (/platform, Phase 5).
 *
 * Routes: auth → platform (Super Admin only) → csrf on writes. There is no
 * "tenant" middleware: the Super Admin has no session condominium. The target
 * condominium is the {id} in the URL, and every action loads it first
 * (findOrFail → 404 for an unknown id) before doing anything. Each change is
 * audited by the service with that condominium id.
 */
final class CondominiumController extends Controller
{
    private const KEEP = [
        'name', 'legal_id', 'email', 'contact_name', 'phone', 'address_line', 'city',
        'state_province', 'postal_code', 'timezone', 'billing_due_day', 'plan',
    ];

    /** GET /platform/condominiums?q=&status=&page= */
    public function index(): Response
    {
        $filters = [];
        $query = [];
        $q = mb_substr($this->request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        $status = $this->request->queryString('status');
        if (array_key_exists($status, Condominium::STATUSES)) {
            $filters['status'] = $query['status'] = $status;
        }

        $condominiums = new Condominium();
        $pagination = Pagination::fromRequest($this->request, 25)->withTotal($condominiums->count($filters));

        return $this->view('platform/condominiums/index', [
            'title'        => 'Condomínios',
            'activeNav'    => 'platform-condominiums',
            'condominiums' => $condominiums->search($filters, $pagination),
            'statuses'     => Condominium::STATUSES,
            'plans'        => Condominium::PLANS,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
        ]);
    }

    /** GET /platform/condominiums/new */
    public function create(): Response
    {
        return $this->form(null);
    }

    /** POST /platform/condominiums */
    public function store(): Response
    {
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/platform/condominiums/new', 422, self::KEEP);
        }

        try {
            $id = (new CondominiumService($this->request))->create($data);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/platform/condominiums/new', $e->status(), self::KEEP);
        }

        return $this->done('Condomínio cadastrado. Agora convide o primeiro síndico.', "/platform/condominiums/{$id}", ['id' => $id], 201);
    }

    /** GET /platform/condominiums/{id}: details, managers, invite form, suspension. */
    public function show(string $id): Response
    {
        $condominium = $this->findOrFail($id);

        return $this->view('platform/condominiums/show', [
            'title'        => (string) $condominium['name'],
            'activeNav'    => 'platform-condominiums',
            'condominium'  => $condominium,
            'managers'     => (new Condominium())->managers((int) $condominium['id']),
            'statuses'     => Condominium::STATUSES,
            'plans'        => Condominium::PLANS,
            'scripts'      => ['js/admin/confirm.js'],
        ]);
    }

    /** GET /platform/condominiums/{id}/edit */
    public function edit(string $id): Response
    {
        return $this->form($this->findOrFail($id));
    }

    /** POST /platform/condominiums/{id} */
    public function update(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}/edit";
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new CondominiumService($this->request))->update((int) $condominium['id'], $data);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Dados do condomínio atualizados.', "/platform/condominiums/{$condominium['id']}");
    }

    /** POST /platform/condominiums/{id}/suspend */
    public function suspend(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";
        $v = new Validator($this->request);
        $reason = $v->string('suspension_reason', 'Motivo', 5, 255);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new CondominiumService($this->request))->suspend((int) $condominium['id'], (string) $reason);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Condomínio suspenso. Os usuários dele perdem o acesso a partir da próxima ação.', $back);
    }

    /** POST /platform/condominiums/{id}/reactivate */
    public function reactivate(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";

        try {
            (new CondominiumService($this->request))->reactivate((int) $condominium['id']);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Condomínio reativado.', $back);
    }

    /**
     * POST /platform/condominiums/{id}/managers: invites a Property Manager.
     * The role is fixed in code ("manager"); the form has no role field.
     */
    public function inviteManager(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";
        if ($condominium['status'] !== 'active') {
            return $this->invalid(['general' => 'Reative o condomínio antes de convidar síndicos.'], $back, 409);
        }

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $email = $v->email('email');
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['full_name', 'email']);
        }

        try {
            $result = (new InvitationService($this->request))->invite(
                (int) $condominium['id'],   // from the URL, validated by findOrFail() above
                (string) $email,
                (string) $name,
                'manager',
                null,
                null,
                (int) Auth::id()
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $back, $e->status(), ['full_name', 'email']);
        }

        return $this->done(
            $result['email_sent'] ? "Convite enviado para {$email}." : 'Síndico cadastrado, mas o e-mail não pôde ser enviado agora. Reenvie o convite.',
            $back,
            ['user_id' => $result['user_id']],
            201
        );
    }

    /** POST /platform/condominiums/{id}/managers/{userId}/resend */
    public function resendManagerInvitation(string $id, string $userId): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";

        try {
            $sent = (new InvitationService($this->request))->resend((int) $condominium['id'], (int) $userId, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            if ($e->status() === 404) {
                throw new HttpException(404);
            }

            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done($sent ? 'Convite reenviado.' : 'Novo convite gerado, mas o e-mail não pôde ser enviado agora.', $back);
    }

    /**
     * The condominium in the URL, or 404. The id is only a lookup key; nothing
     * else about the request decides which tenant is affected.
     *
     * @return array<string, mixed>
     */
    private function findOrFail(string $id): array
    {
        return (new Condominium())->find((int) $id) ?? throw new HttpException(404);
    }

    /** @param array<string, mixed>|null $condominium */
    private function form(?array $condominium): Response
    {
        return $this->view('platform/condominiums/form', [
            'title'       => $condominium === null ? 'Novo condomínio' : 'Editar condomínio',
            'activeNav'   => 'platform-condominiums',
            'condominium' => $condominium,
            'plans'       => Condominium::PLANS,
            'timezones'   => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR'),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $legalId = null;
        if ($v->filled('legal_id')) {
            $legalId = CondominiumService::normalizeCnpj($this->request->string('legal_id'));
            if ($legalId === null) {
                $v->addError('legal_id', 'CNPJ inválido.');
            }
        }
        $postal = preg_replace('/\D/', '', $this->request->string('postal_code')) ?? '';
        if (strlen($postal) !== 8) {
            $v->addError('postal_code', 'CEP inválido.');
        }
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR');

        $data = [
            'name'            => $v->string('name', 'Nome', 3, 150),
            'legal_id'        => $legalId,
            'email'           => $v->filled('email') ? $v->email('email', 'E-mail de contato') : null,
            'contact_name'    => $v->string('contact_name', 'Responsável', 3, 150, required: false),
            'phone'           => $v->phone('phone'),
            'address_line'    => $v->string('address_line', 'Endereço', 3, 200),
            'city'            => $v->string('city', 'Cidade', 2, 100),
            'state_province'  => $v->string('state_province', 'UF', 2, 50),
            'postal_code'     => substr($postal, 0, 5) . '-' . substr($postal, 5),
            'timezone'        => $v->enum('timezone', 'Fuso horário', $timezones),
            'billing_due_day' => $v->integer('billing_due_day', 'Dia de vencimento', 1, 28, 10),
            'plan'            => $v->enum('plan', 'Plano', array_keys(Condominium::PLANS)),
        ];

        return [$data, $v];
    }
}
