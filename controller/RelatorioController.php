<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . '/../model/dao/Conexao.php';
require_once __DIR__ . '/PermissaoController.php';

class RelatorioController
{
    private PDO $conexao;

    public function __construct()
    {
        PermissaoController::exigirGestao();

        $this->conexao = Conexao::conectar();
    }

    /**
     * Normaliza uma data recebida pelo formulário.
     */
    private function normalizarData(?string $data): ?string
    {
        if (!$data) {
            return null;
        }

        $data = trim($data);

        $objetoData = DateTime::createFromFormat('Y-m-d', $data);

        if (!$objetoData || $objetoData->format('Y-m-d') !== $data) {
            return null;
        }

        return $data;
    }

    /**
     * Monta filtro de período para campos DATETIME/DATE.
     *
     * O retorno possui:
     * - sql
     * - parametros
     */
    private function filtroPeriodo(
        string $campo,
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $dataInicio = $this->normalizarData($dataInicio);
        $dataFim = $this->normalizarData($dataFim);

        $where = [];
        $parametros = [];

        if ($dataInicio) {
            $where[] = "{$campo} >= :data_inicio";
            $parametros[':data_inicio'] = $dataInicio . ' 00:00:00';
        }

        if ($dataFim) {
            $where[] = "{$campo} <= :data_fim";
            $parametros[':data_fim'] = $dataFim . ' 23:59:59';
        }

        if (empty($where)) {
            return [
                'sql' => '',
                'parametros' => []
            ];
        }

        return [
            'sql' => ' WHERE ' . implode(' AND ', $where),
            'parametros' => $parametros
        ];
    }

    /**
     * Executa uma consulta preparada.
     */
    private function executar(
        string $sql,
        array $parametros = []
    ): array {
        $stmt = $this->conexao->prepare($sql);

        foreach ($parametros as $chave => $valor) {
            $stmt->bindValue($chave, $valor);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Indicadores gerais.
     *
     * Usuários e unidades representam o estado atual do condomínio.
     * Os demais indicadores respeitam o período informado.
     */
    public function resumoGeral(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtroReserva = $this->filtroPeriodo(
            'data_inicio',
            $dataInicio,
            $dataFim
        );

        $filtroOcorrencia = $this->filtroPeriodo(
            'data_abertura',
            $dataInicio,
            $dataFim
        );

        $filtroEncomenda = $this->filtroPeriodo(
            'data_recebimento',
            $dataInicio,
            $dataFim
        );

        $filtroAcesso = $this->filtroPeriodo(
            'data_hora',
            $dataInicio,
            $dataFim
        );

        $filtroCobranca = $this->filtroPeriodo(
            'vencimento',
            $dataInicio,
            $dataFim
        );

        return [
            'usuarios_ativos' => $this->contar(
                "SELECT COUNT(*) FROM usuario WHERE ativo = 1"
            ),

            'moradores' => $this->contar(
                "SELECT COUNT(*)
                 FROM usuario
                 WHERE ativo = 1
                 AND perfil IN ('Morador', 'Sindico')"
            ),

            'porteiros' => $this->contar(
                "SELECT COUNT(*)
                 FROM usuario
                 WHERE ativo = 1
                 AND perfil = 'Porteiro'"
            ),

            'administradores' => $this->contar(
                "SELECT COUNT(*)
                 FROM usuario
                 WHERE ativo = 1
                 AND perfil = 'Administrador'"
            ),

            'unidades' => $this->contar(
                "SELECT COUNT(*) FROM unidade"
            ),

            'reservas' => $this->contar(
                "SELECT COUNT(*)
                 FROM reserva"
                . $filtroReserva['sql'],
                $filtroReserva['parametros']
            ),

            'ocorrencias' => $this->contar(
                "SELECT COUNT(*)
                 FROM ocorrencia"
                . $filtroOcorrencia['sql'],
                $filtroOcorrencia['parametros']
            ),

            'encomendas' => $this->contar(
                "SELECT COUNT(*)
                 FROM encomenda"
                . $filtroEncomenda['sql'],
                $filtroEncomenda['parametros']
            ),

            'visitantes' => $this->contar(
                "SELECT COUNT(*) FROM visitante"
            ),

            'acessos' => $this->contar(
                "SELECT COUNT(*)
                 FROM registro_acesso"
                . $filtroAcesso['sql'],
                $filtroAcesso['parametros']
            ),

            'cobrancas' => $this->contar(
                "SELECT COUNT(*)
                 FROM cobranca"
                . $filtroCobranca['sql'],
                $filtroCobranca['parametros']
            )
        ];
    }

    /**
     * Usuários por perfil.
     */
    public function usuariosPorPerfil(): array
    {
        return $this->executar(
            "SELECT
                perfil,
                COUNT(*) AS quantidade
             FROM usuario
             WHERE ativo = 1
             GROUP BY perfil
             ORDER BY quantidade DESC"
        );
    }

    /**
     * Reservas por status.
     */
    public function reservasPorStatus(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'data_inicio',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                status,
                COUNT(*) AS quantidade
             FROM reserva"
            . $filtro['sql']
            . " GROUP BY status
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Ocorrências por status.
     */
    public function ocorrenciasPorStatus(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'data_abertura',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                status,
                COUNT(*) AS quantidade
             FROM ocorrencia"
            . $filtro['sql']
            . " GROUP BY status
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Encomendas por status.
     */
    public function encomendasPorStatus(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'data_recebimento',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                status,
                COUNT(*) AS quantidade
             FROM encomenda"
            . $filtro['sql']
            . " GROUP BY status
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Cobranças por status.
     */
    public function cobrancasPorStatus(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'vencimento',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                status,
                COUNT(*) AS quantidade,
                COALESCE(SUM(valor), 0) AS total
             FROM cobranca"
            . $filtro['sql']
            . " GROUP BY status
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Acessos por método.
     */
    public function acessosPorMetodo(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'data_hora',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                metodo_leitura,
                COUNT(*) AS quantidade
             FROM registro_acesso"
            . $filtro['sql']
            . " GROUP BY metodo_leitura
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Acessos por direção.
     */
    public function acessosPorDirecao(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $filtro = $this->filtroPeriodo(
            'data_hora',
            $dataInicio,
            $dataFim
        );

        return $this->executar(
            "SELECT
                direcao,
                COUNT(*) AS quantidade
             FROM registro_acesso"
            . $filtro['sql']
            . " GROUP BY direcao
                ORDER BY quantidade DESC",
            $filtro['parametros']
        );
    }

    /**
     * Reservas recentes.
     */
    public function reservasRecentes(
        int $limite = 10,
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $limite = max(1, min($limite, 50));

        $filtro = $this->filtroPeriodo(
            'r.data_inicio',
            $dataInicio,
            $dataFim
        );

        $where = '';

        if ($filtro['sql']) {
            $where = str_replace(
                ' WHERE ',
                ' AND ',
                $filtro['sql']
            );
        }

        $sql = "
            SELECT
                r.id,
                r.espaco_nome,
                r.data_inicio,
                r.data_fim,
                r.status,
                r.valor_taxa,
                u.nome AS usuario_nome,
                un.identificacao AS unidade_identificacao
            FROM reserva r
            INNER JOIN usuario u
                ON u.id = r.usuario_id
            LEFT JOIN unidade un
                ON un.id = u.unidade_id
            WHERE 1 = 1
            {$where}
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT {$limite}
        ";

        return $this->executar(
            $sql,
            $filtro['parametros']
        );
    }

    /**
     * Ocorrências recentes.
     */
    public function ocorrenciasRecentes(
        int $limite = 10,
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $limite = max(1, min($limite, 50));

        $filtro = $this->filtroPeriodo(
            'o.data_abertura',
            $dataInicio,
            $dataFim
        );

        $where = '';

        if ($filtro['sql']) {
            $where = str_replace(
                ' WHERE ',
                ' AND ',
                $filtro['sql']
            );
        }

        $sql = "
            SELECT
                o.id,
                o.tipo,
                o.titulo,
                o.status,
                o.data_abertura,
                o.data_fechamento,
                u.nome AS usuario_nome,
                un.identificacao AS unidade_identificacao
            FROM ocorrencia o
            INNER JOIN usuario u
                ON u.id = o.usuario_id
            LEFT JOIN unidade un
                ON un.id = u.unidade_id
            WHERE 1 = 1
            {$where}
            ORDER BY o.data_abertura DESC, o.id DESC
            LIMIT {$limite}
        ";

        return $this->executar(
            $sql,
            $filtro['parametros']
        );
    }

    /**
     * Encomendas recentes.
     */
    public function encomendasRecentes(
        int $limite = 10,
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $limite = max(1, min($limite, 50));

        $filtro = $this->filtroPeriodo(
            'e.data_recebimento',
            $dataInicio,
            $dataFim
        );

        $where = '';

        if ($filtro['sql']) {
            $where = str_replace(
                ' WHERE ',
                ' AND ',
                $filtro['sql']
            );
        }

        $sql = "
            SELECT
                e.id,
                e.descricao,
                e.codigo_rastreio,
                e.data_recebimento,
                e.data_retirada,
                e.status,
                un.identificacao AS unidade_identificacao,
                u.nome AS usuario_nome
            FROM encomenda e
            INNER JOIN unidade un
                ON un.id = e.unidade_id
            LEFT JOIN usuario u
                ON u.id = e.usuario_recebedor_id
            WHERE 1 = 1
            {$where}
            ORDER BY e.data_recebimento DESC, e.id DESC
            LIMIT {$limite}
        ";

        return $this->executar(
            $sql,
            $filtro['parametros']
        );
    }

    /**
     * Acessos recentes.
     */
    public function acessosRecentes(
        int $limite = 10,
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): array {
        $limite = max(1, min($limite, 50));

        $filtro = $this->filtroPeriodo(
            'ra.data_hora',
            $dataInicio,
            $dataFim
        );

        $where = '';

        if ($filtro['sql']) {
            $where = str_replace(
                ' WHERE ',
                ' AND ',
                $filtro['sql']
            );
        }

        $sql = "
            SELECT
                ra.id,
                ra.data_hora,
                ra.direcao,
                ra.metodo_leitura,
                ra.observacao,
                u.nome AS usuario_nome,
                v.nome AS visitante_nome
            FROM registro_acesso ra
            LEFT JOIN usuario u
                ON u.id = ra.usuario_id
            LEFT JOIN visitante v
                ON v.id = ra.visitante_id
            WHERE 1 = 1
            {$where}
            ORDER BY ra.data_hora DESC, ra.id DESC
            LIMIT {$limite}
        ";

        return $this->executar(
            $sql,
            $filtro['parametros']
        );
    }

    /**
     * Total financeiro.
     */
    public function totalCobrancas(
        ?string $dataInicio = null,
        ?string $dataFim = null
    ): float {
        $filtro = $this->filtroPeriodo(
            'vencimento',
            $dataInicio,
            $dataFim
        );

        $sql = "
            SELECT COALESCE(SUM(valor), 0)
            FROM cobranca
            " . $filtro['sql'];

        $stmt = $this->conexao->prepare($sql);

        foreach ($filtro['parametros'] as $chave => $valor) {
            $stmt->bindValue($chave, $valor);
        }

        $stmt->execute();

        return (float) $stmt->fetchColumn();
    }

    /**
     * Quantidade simples.
     */
    private function contar(
        string $sql,
        array $parametros = []
    ): int {
        $stmt = $this->conexao->prepare($sql);

        foreach ($parametros as $chave => $valor) {
            $stmt->bindValue($chave, $valor);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}