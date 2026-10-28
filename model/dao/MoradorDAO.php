<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/VeiculoDTO.php";
require_once __DIR__ . "/../dto/ReservaDTO.php";
require_once __DIR__ . "/../dto/OcorrenciaDTO.php";
require_once __DIR__ . "/../../service/OcorrenciaHistorico.php";

class MoradorDAO
{
    private PDO $conexao;

    private string $ultimoErroReserva = "";

    private int $ultimaOcorrenciaId = 0;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }


    /*
     * ==========================================================
     * DADOS DO USUÁRIO
     * ==========================================================
     */

    public function buscarUsuario(int $usuarioId): ?array
    {
        $sql = "
            SELECT
                u.id,
                u.nome,
                u.cpf,
                u.email,
                u.perfil,
                u.unidade_id,
                u.termo_lgpd,
                u.data_aceite_lgpd,
                u.ativo,
                un.identificacao AS unidade_identificacao,
                un.tipo_unidade
            FROM usuario u
            LEFT JOIN unidade un
                ON un.id = u.unidade_id
            WHERE u.id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        return $dados ?: null;
    }


    /*
     * ==========================================================
     * UNIDADE
     * ==========================================================
     */

    public function buscarUnidade(int $usuarioId): ?array
    {
        $sql = "
            SELECT
                un.id,
                un.identificacao,
                un.tipo_unidade,
                un.quantidade_vagas,
                un.fracao_ideal,
                un.created_at
            FROM unidade un
            INNER JOIN usuario u
                ON u.unidade_id = un.id
            WHERE u.id = :usuario_id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        return $dados ?: null;
    }


    /*
     * ==========================================================
     * VEÍCULOS
     * ==========================================================
     */

    public function listarVeiculos(int $usuarioId): array
    {
        $sql = "
            SELECT
                id,
                usuario_id,
                placa,
                modelo,
                cor,
                created_at
            FROM veiculo
            WHERE usuario_id = :usuario_id
            ORDER BY id DESC
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function buscarVeiculo(
        int $veiculoId,
        int $usuarioId
    ): ?VeiculoDTO {

        $sql = "
            SELECT *
            FROM veiculo
            WHERE id = :id
              AND usuario_id = :usuario_id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $veiculoId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        $veiculo = new VeiculoDTO();

        $veiculo->setId((int) $dados["id"]);
        $veiculo->setUsuarioId((int) $dados["usuario_id"]);
        $veiculo->setPlaca($dados["placa"] ?? "");
        $veiculo->setModelo($dados["modelo"] ?? "");
        $veiculo->setCor($dados["cor"] ?? "");
        $veiculo->setCreatedAt($dados["created_at"] ?? null);

        return $veiculo;
    }


    public function cadastrarVeiculo(
        VeiculoDTO $veiculo
    ): bool {

        $sql = "
            INSERT INTO veiculo (
                usuario_id,
                placa,
                modelo,
                cor
            )
            VALUES (
                :usuario_id,
                :placa,
                :modelo,
                :cor
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $veiculo->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":placa",
            strtoupper(trim($veiculo->getPlaca())),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":modelo",
            trim($veiculo->getModelo()),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":cor",
            trim($veiculo->getCor()),
            PDO::PARAM_STR
        );

        return $stmt->execute();
    }


    public function editarVeiculo(
        VeiculoDTO $veiculo,
        int $usuarioId
    ): bool {

        $sql = "
            UPDATE veiculo
            SET
                placa = :placa,
                modelo = :modelo,
                cor = :cor
            WHERE id = :id
              AND usuario_id = :usuario_id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":placa",
            strtoupper(trim($veiculo->getPlaca())),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":modelo",
            trim($veiculo->getModelo()),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":cor",
            trim($veiculo->getCor()),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":id",
            $veiculo->getId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    public function excluirVeiculo(
        int $veiculoId,
        int $usuarioId
    ): bool {

        $sql = "
            DELETE FROM veiculo
            WHERE id = :id
              AND usuario_id = :usuario_id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $veiculoId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * ENCOMENDAS
     * ==========================================================
     */

    public function listarEncomendas(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                e.*,
                un.identificacao
            FROM encomenda e
            INNER JOIN usuario u
                ON u.unidade_id = e.unidade_id
            INNER JOIN unidade un
                ON un.id = e.unidade_id
            WHERE u.id = :usuario_id
            ORDER BY e.data_recebimento DESC
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /*
     * ==========================================================
     * RESERVAS
     * ==========================================================
     */

    public function listarReservas(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                r.*,
                lr.nome AS local_nome,
                (
                    SELECT c.status
                    FROM cobranca c
                    WHERE c.unidade_id = (
                        SELECT u.unidade_id
                        FROM usuario u
                        WHERE u.id = r.usuario_id
                        LIMIT 1
                    )
                    AND c.tipo_cobranca = 'Reserva'
                    AND c.descricao = CONCAT(
                        'Reserva #',
                        r.id,
                        ' - ',
                        r.espaco_nome
                    )
                    AND c.status <> 'Cancelado'
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS pagamento_status,
                (
                    SELECT c.id
                    FROM cobranca c
                    WHERE c.unidade_id = (
                        SELECT u.unidade_id FROM usuario u WHERE u.id = r.usuario_id LIMIT 1
                    )
                    AND c.tipo_cobranca = 'Reserva'
                    AND c.descricao = CONCAT('Reserva #', r.id, ' - ', r.espaco_nome)
                    AND c.status <> 'Cancelado'
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS pagamento_id
            FROM reserva r
            LEFT JOIN local_reserva lr
                ON lr.id = r.local_reserva_id
            WHERE r.usuario_id = :usuario_id
            ORDER BY r.data_inicio DESC
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /*
     * ==========================================================
     * CANCELAR RESERVA
     * ==========================================================
     */

    public function cancelarReserva(
        int $reservaId,
        int $usuarioId
    ): bool {

        $sql = "
            UPDATE reserva
            SET
                status = 'Cancelada'
            WHERE id = :id
              AND usuario_id = :usuario_id
              AND status IN (
                    'Pendente',
                    'Aguardando_Pagamento',
                    'Confirmada'
              )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $reservaId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * LOCAL DE RESERVA
     * ==========================================================
     */

    private function buscarLocalReserva(
        string $espacoNome
    ): ?array {

        $sql = "
            SELECT *
            FROM local_reserva
            WHERE LOWER(TRIM(nome)) =
                  LOWER(TRIM(:nome))
              AND ativo = 1
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":nome",
            $espacoNome,
            PDO::PARAM_STR
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        return $dados ?: null;
    }


    /*
     * ==========================================================
     * VERIFICAÇÃO DE CONFLITO
     * ==========================================================
     */

    private function existeConflitoReserva(
        string $espacoNome,
        string $dataInicio,
        string $dataFim
    ): bool {

        $sql = "
            SELECT
                id
            FROM reserva
            WHERE LOWER(TRIM(espaco_nome)) =
                  LOWER(TRIM(:espaco_nome))
              AND status IN (
                    'Pendente',
                    'Aguardando_Pagamento',
                    'Confirmada'
              )
              AND data_inicio < :data_fim
              AND data_fim > :data_inicio
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":espaco_nome",
            $espacoNome,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_inicio",
            $dataInicio,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_fim",
            $dataFim,
            PDO::PARAM_STR
        );

        $stmt->execute();

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }


    /*
     * ==========================================================
     * VERIFICAR DISPONIBILIDADE
     * ==========================================================
     */

    public function verificarDisponibilidadeReserva(
        string $espacoNome,
        string $dataInicio,
        string $dataFim
    ): array {

        $espacoNome = trim($espacoNome);

        if ($espacoNome === "") {

            return [
                "disponivel" => false,
                "mensagem" =>
                    "Selecione um local para verificar a disponibilidade."
            ];
        }

        if (
            trim($dataInicio) === "" ||
            trim($dataFim) === ""
        ) {

            return [
                "disponivel" => false,
                "mensagem" =>
                    "Informe o início e o término da reserva."
            ];
        }

        $local =
            $this->buscarLocalReserva(
                $espacoNome
            );

        if (!$local) {

            return [
                "disponivel" => false,
                "mensagem" =>
                    "O local selecionado não está disponível."
            ];
        }

        $conflito =
            $this->existeConflitoReserva(
                $local["nome"],
                $dataInicio,
                $dataFim
            );

        if ($conflito) {

            return [
                "disponivel" => false,
                "mensagem" =>
                    "Horário indisponível. Já existe uma reserva para este local nesse período."
            ];
        }

        return [
            "disponivel" => true,
            "mensagem" =>
                "Horário disponível para reserva."
        ];
    }


    /*
     * ==========================================================
     * LIMITE DE RESERVAS DA UNIDADE
     * ==========================================================
     */

    private function quantidadeReservasUnidade(
        int $usuarioId,
        string $espacoNome,
        string $dataInicio,
        string $periodoLimite
    ): int {

        $sql = "
            SELECT
                COUNT(*) AS total
            FROM reserva r
            INNER JOIN usuario u
                ON u.id = r.usuario_id
            WHERE u.unidade_id = (
                SELECT unidade_id
                FROM usuario
                WHERE id = :usuario_id
                LIMIT 1
            )
              AND LOWER(TRIM(r.espaco_nome)) =
                  LOWER(TRIM(:espaco_nome))
              AND r.status IN (
                    'Pendente',
                    'Aguardando_Pagamento',
                    'Confirmada'
              )
        ";

        $parametros = [
            ":usuario_id" => $usuarioId,
            ":espaco_nome" => $espacoNome
        ];

        if ($periodoLimite === "Dia") {

            $sql .= "
                AND DATE(r.data_inicio) =
                    DATE(:data_inicio)
            ";

            $parametros[":data_inicio"] = $dataInicio;

        } elseif ($periodoLimite === "Mes") {

            $sql .= "
                AND YEAR(r.data_inicio) =
                    YEAR(:data_inicio)
                AND MONTH(r.data_inicio) =
                    MONTH(:data_inicio)
            ";

            $parametros[":data_inicio"] = $dataInicio;
        }

        $stmt = $this->conexao->prepare($sql);

        foreach ($parametros as $chave => $valor) {

            if ($chave === ":usuario_id") {

                $stmt->bindValue(
                    $chave,
                    $valor,
                    PDO::PARAM_INT
                );

            } else {

                $stmt->bindValue(
                    $chave,
                    $valor,
                    PDO::PARAM_STR
                );
            }
        }

        $stmt->execute();

        $dados =
            $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) (
            $dados["total"] ?? 0
        );
    }


    /*
     * ==========================================================
     * CRIAR RESERVA
     * ==========================================================
     */

    public function criarReserva(
        int $usuarioId,
        string $espacoNome,
        string $dataInicio,
        string $dataFim,
        array $listaPresenca = []
    ): bool {

        $this->ultimoErroReserva = "";

        $espacoNome = trim($espacoNome);

        if ($espacoNome === "") {

            $this->ultimoErroReserva =
                "Informe o local da reserva.";

            return false;
        }

        $listaPresenca = ReservaDTO::listaDeTexto(
            implode("\n", $listaPresenca)
        );
        $quantidadePessoas = count($listaPresenca);

        try {

            $inicio = new DateTime($dataInicio);
            $fim = new DateTime($dataFim);

        } catch (Throwable $e) {

            $this->ultimoErroReserva =
                "As datas da reserva são inválidas.";

            return false;
        }

        $agora = new DateTime();

        if ($inicio <= $agora) {

            $this->ultimoErroReserva =
                "A data e horário inicial da reserva devem ser futuros.";

            return false;
        }

        if ($fim <= $inicio) {

            $this->ultimoErroReserva =
                "O horário final deve ser posterior ao horário inicial.";

            return false;
        }


        /*
         * ------------------------------------------------------
         * LOCAL
         * ------------------------------------------------------
         */

        $local =
            $this->buscarLocalReserva(
                $espacoNome
            );

        if (!$local) {

            $this->ultimoErroReserva =
                "O local selecionado não está disponível para reserva.";

            return false;
        }

        $localReservaId =
            (int) $local["id"];

        $espacoNome =
            trim($local["nome"]);


        /*
         * ------------------------------------------------------
         * CAPACIDADE
         * ------------------------------------------------------
         */

        $capacidade =
            (int) (
                $local["capacidade"] ?? 1
            );

        if (
            $quantidadePessoas >
            $capacidade
        ) {

            $this->ultimoErroReserva =
                "A lista de presença excede a capacidade do local. " .
                "Capacidade máxima: " .
                $capacidade .
                " pessoa(s).";

            return false;
        }


        /*
         * ------------------------------------------------------
         * DIA DA SEMANA
         * ------------------------------------------------------
         */

        $diaSemana =
            (int) $inicio->format("N");

        $diasPermitidos = [
            1 => (bool) ($local["segunda"] ?? false),
            2 => (bool) ($local["terca"] ?? false),
            3 => (bool) ($local["quarta"] ?? false),
            4 => (bool) ($local["quinta"] ?? false),
            5 => (bool) ($local["sexta"] ?? false),
            6 => (bool) ($local["sabado"] ?? false),
            7 => (bool) ($local["domingo"] ?? false)
        ];

        if (
            empty($diasPermitidos[$diaSemana])
        ) {

            $nomesDias = [
                1 => "segunda-feira",
                2 => "terça-feira",
                3 => "quarta-feira",
                4 => "quinta-feira",
                5 => "sexta-feira",
                6 => "sábado",
                7 => "domingo"
            ];

            $this->ultimoErroReserva =
                "O local não permite reservas às " .
                $nomesDias[$diaSemana] .
                ".";

            return false;
        }


        /*
         * ------------------------------------------------------
         * HORÁRIO
         * ------------------------------------------------------
         */

        $horarioInicio =
            substr(
                (string) (
                    $local["horario_inicio"] ??
                    "00:00"
                ),
                0,
                5
            );

        $horarioFim =
            substr(
                (string) (
                    $local["horario_fim"] ??
                    "23:59"
                ),
                0,
                5
            );

        $horaInicioReserva =
            $inicio->format("H:i");

        $horaFimReserva =
            $fim->format("H:i");

        if (
            $horaInicioReserva < $horarioInicio ||
            $horaFimReserva > $horarioFim
        ) {

            $this->ultimoErroReserva =
                "O horário escolhido está fora do período permitido. " .
                "Horário permitido: " .
                $horarioInicio .
                " às " .
                $horarioFim .
                ".";

            return false;
        }


        /*
         * ------------------------------------------------------
         * DURAÇÃO
         * ------------------------------------------------------
         */

        $duracaoMinutos =
            (int) round(
                (
                    $fim->getTimestamp() -
                    $inicio->getTimestamp()
                ) / 60
            );

        $duracaoMinima =
            (int) (
                $local["duracao_minutos_minima"] ??
                60
            );

        $duracaoMaxima =
            (int) (
                $local["duracao_minutos_maxima"] ??
                1440
            );

        if (
            $duracaoMinutos <
            $duracaoMinima
        ) {

            $this->ultimoErroReserva =
                "A duração mínima da reserva é de " .
                $this->formatarDuracao(
                    $duracaoMinima
                ) .
                ".";

            return false;
        }

        if (
            $duracaoMinutos >
            $duracaoMaxima
        ) {

            $this->ultimoErroReserva =
                "A duração máxima da reserva é de " .
                $this->formatarDuracao(
                    $duracaoMaxima
                ) .
                ".";

            return false;
        }


        /*
         * ------------------------------------------------------
         * ANTECEDÊNCIA
         * ------------------------------------------------------
         */

        $hoje =
            new DateTime("today");

        $dataReserva =
            new DateTime(
                $inicio->format("Y-m-d")
            );

        $antecedenciaDias =
            (int) $hoje
                ->diff($dataReserva)
                ->format("%r%a");

        $antecedenciaMinima =
            (int) (
                $local["antecedencia_min_dias"] ??
                0
            );

        $antecedenciaMaxima =
            (int) (
                $local["antecedencia_max_dias"] ??
                30
            );

        if (
            $antecedenciaDias <
            $antecedenciaMinima
        ) {

            $this->ultimoErroReserva =
                "A reserva precisa ser feita com pelo menos " .
                $antecedenciaMinima .
                " dia(s) de antecedência.";

            return false;
        }

        if (
            $antecedenciaDias >
            $antecedenciaMaxima
        ) {

            $this->ultimoErroReserva =
                "A reserva só pode ser feita com até " .
                $antecedenciaMaxima .
                " dia(s) de antecedência.";

            return false;
        }


        /*
         * ------------------------------------------------------
         * UNIDADE
         * ------------------------------------------------------
         */

        $sqlUnidade = "
            SELECT
                unidade_id
            FROM usuario
            WHERE id = :usuario_id
            LIMIT 1
        ";

        $stmtUnidade =
            $this->conexao->prepare(
                $sqlUnidade
            );

        $stmtUnidade->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmtUnidade->execute();

        $usuario =
            $stmtUnidade->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            !$usuario ||
            empty($usuario["unidade_id"])
        ) {

            $this->ultimoErroReserva =
                "Seu usuário não possui uma unidade vinculada. " .
                "Entre em contato com a administração.";

            return false;
        }


        /*
         * ------------------------------------------------------
         * LIMITE DA UNIDADE
         * ------------------------------------------------------
         */

        $limiteReservas =
            (int) (
                $local["limite_reservas_unidade"] ??
                1
            );

        $periodoLimite =
            trim(
                (string) (
                    $local["periodo_limite"] ??
                    "Dia"
                )
            );

        if ($limiteReservas > 0) {

            $totalReservas =
                $this->quantidadeReservasUnidade(
                    $usuarioId,
                    $espacoNome,
                    $dataInicio,
                    $periodoLimite
                );

            if (
                $totalReservas >=
                $limiteReservas
            ) {

                $periodoTexto =
                    match (
                        strtolower($periodoLimite)
                    ) {

                        "dia" =>
                            "por dia",

                        "mes", "mês" =>
                            "por mês",

                        "total" =>
                            "no total",

                        default =>
                            "no período permitido"
                    };

                $this->ultimoErroReserva =
                    "A unidade já atingiu o limite de " .
                    $limiteReservas .
                    " reserva(s) " .
                    $periodoTexto .
                    " para este local.";

                return false;
            }
        }


        /*
         * ------------------------------------------------------
         * CONFLITO
         * ------------------------------------------------------
         */

        if (
            $this->existeConflitoReserva(
                $espacoNome,
                $dataInicio,
                $dataFim
            )
        ) {

            $this->ultimoErroReserva =
                "Já existe uma reserva para este local " .
                "no período informado.";

            return false;
        }


        /*
         * ------------------------------------------------------
         * TAXA
         * ------------------------------------------------------
         */

        $gratuito =
            (int) (
                $local["gratuito"] ??
                1
            );

        $valor =
            (float) (
                $local["valor"] ??
                0
            );

        $valorTaxa =
            $gratuito === 1
                ? 0.00
                : $valor;


        /*
         * ------------------------------------------------------
         * INSERÇÃO
         *
         * AGORA GRAVA TAMBÉM local_reserva_id.
         * ------------------------------------------------------
         */

        try {

            $sql = "
                INSERT INTO reserva (
                    usuario_id,
                    local_reserva_id,
                    espaco_nome,
                    lista_presenca,
                    data_inicio,
                    data_fim,
                    status,
                    valor_taxa
                )
                VALUES (
                    :usuario_id,
                    :local_reserva_id,
                    :espaco_nome,
                    :lista_presenca,
                    :data_inicio,
                    :data_fim,
                    'Pendente',
                    :valor_taxa
                )
            ";

            $stmt =
                $this->conexao->prepare(
                    $sql
                );

            $stmt->bindValue(
                ":usuario_id",
                $usuarioId,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ":local_reserva_id",
                $localReservaId,
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ":espaco_nome",
                $espacoNome,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":lista_presenca",
                ReservaDTO::listaParaJson($listaPresenca),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":data_inicio",
                $inicio->format(
                    "Y-m-d H:i:s"
                ),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":data_fim",
                $fim->format(
                    "Y-m-d H:i:s"
                ),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":valor_taxa",
                $valorTaxa
            );

            return $stmt->execute();

        } catch (Throwable $e) {

            $this->ultimoErroReserva =
                "Não foi possível criar a reserva.";

            return false;
        }
    }


    /*
     * ==========================================================
     * ÚLTIMO ERRO
     * ==========================================================
     */

    public function getUltimoErroReserva(): string
    {
        return $this->ultimoErroReserva;
    }


    private function formatarDuracao(
        int $minutos
    ): string {

        $horas =
            intdiv(
                $minutos,
                60
            );

        $restante =
            $minutos % 60;

        if (
            $horas > 0 &&
            $restante > 0
        ) {

            return
                $horas .
                "h " .
                $restante .
                "min";
        }

        if ($horas > 0) {

            return
                $horas .
                (
                    $horas === 1
                        ? " hora"
                        : " horas"
                );
        }

        return
            $minutos .
            " minutos";
    }


    /*
     * ==========================================================
     * OCORRÊNCIAS
     * ==========================================================
     */

    public function listarOcorrencias(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                o.*
            FROM ocorrencia o
            WHERE o.usuario_id = :usuario_id
            ORDER BY o.data_abertura DESC
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    public function cadastrarOcorrencia(
        OcorrenciaDTO $ocorrencia
    ): bool {

        $sql = "
            INSERT INTO ocorrencia (
                usuario_id,
                tipo,
                titulo,
                descricao,
                status,
                foto_url,
                data_fechamento
            )
            VALUES (
                :usuario_id,
                :tipo,
                :titulo,
                :descricao,
                'Aberta',
                :foto_url,
                NULL
            )
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":usuario_id",
            $ocorrencia->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo",
            $ocorrencia->getTipo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":titulo",
            $ocorrencia->getTitulo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $ocorrencia->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":foto_url",
            $ocorrencia->getFotoUrl(),
            $ocorrencia->getFotoUrl() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_STR
        );

        $sucesso = $stmt->execute();

        if ($sucesso) {

            $this->ultimaOcorrenciaId = (int) $this->conexao->lastInsertId();

            OcorrenciaHistorico::registrar(
                $this->conexao,
                $this->ultimaOcorrenciaId,
                "Aberta",
                "Ocorrência registrada e enviada à administração."
            );
        }

        return $sucesso;
    }

    public function getUltimaOcorrenciaId(): int
    {
        return $this->ultimaOcorrenciaId;
    }

    /*
 * ==========================================================
 * BUSCAR ÚLTIMA RESERVA DO MORADOR
 * ==========================================================
 */

public function buscarUltimaReservaUsuario(
    int $usuarioId,
    string $espacoNome,
    string $dataInicio,
    string $dataFim
): ?array {

    $sql = "
        SELECT
            id,
            usuario_id,
            espaco_nome,
            lista_presenca,
            data_inicio,
            data_fim,
            status,
            valor_taxa
        FROM reserva
        WHERE usuario_id = :usuario_id
          AND LOWER(TRIM(espaco_nome)) =
              LOWER(TRIM(:espaco_nome))
          AND data_inicio = :data_inicio
          AND data_fim = :data_fim
        ORDER BY id DESC
        LIMIT 1
    ";

    $stmt =
        $this->conexao->prepare($sql);

    $stmt->bindValue(
        ":usuario_id",
        $usuarioId,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ":espaco_nome",
        $espacoNome,
        PDO::PARAM_STR
    );

    $stmt->bindValue(
        ":data_inicio",
        $dataInicio,
        PDO::PARAM_STR
    );

    $stmt->bindValue(
        ":data_fim",
        $dataFim,
        PDO::PARAM_STR
    );

    $stmt->execute();

    $dados =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return $dados ?: null;
}


/*
 * ==========================================================
 * BUSCAR RESERVA DO PRÓPRIO MORADOR
 * ==========================================================
 */

public function buscarReservaDoUsuario(
    int $reservaId,
    int $usuarioId
): ?array {

    $sql = "
        SELECT
            id,
            usuario_id,
            espaco_nome,
            lista_presenca,
            data_inicio,
            data_fim,
            status,
            valor_taxa
        FROM reserva
        WHERE id = :id
          AND usuario_id = :usuario_id
        LIMIT 1
    ";

    $stmt =
        $this->conexao->prepare($sql);

    $stmt->bindValue(
        ":id",
        $reservaId,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ":usuario_id",
        $usuarioId,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $dados =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return $dados ?: null;
}

    /*
     * ==========================================================
     * COBRANÇAS
     * ==========================================================
     */

    public function listarCobrancas(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                c.*
            FROM cobranca c
            INNER JOIN usuario u
                ON u.unidade_id = c.unidade_id
            WHERE u.id = :usuario_id
            ORDER BY c.vencimento DESC
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /*
     * ==========================================================
     * COBRANÇA ESPECÍFICA DO MORADOR
     * ==========================================================
     */

    public function buscarCobrancaUsuario(
        int $usuarioId,
        int $cobrancaId
    ): ?array {

        $sql = "
            SELECT
                c.*
            FROM cobranca c
            INNER JOIN usuario u
                ON u.unidade_id = c.unidade_id
            WHERE u.id = :usuario_id
              AND c.id = :cobranca_id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':cobranca_id', $cobrancaId, PDO::PARAM_INT);
        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        return $dados ?: null;
    }


    /*
     * ==========================================================
     * AUTORIZAÇÕES
     * ==========================================================
     */

    public function listarAutorizacoes(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                a.*,
                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.tipo_visitante
            FROM autorizacao_acesso a
            INNER JOIN usuario u
                ON u.unidade_id = a.unidade_id
            INNER JOIN visitante v
                ON v.id = a.visitante_id
            WHERE u.id = :usuario_id
            ORDER BY a.data_inicio DESC
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /*
     * ==========================================================
     * PUBLICAÇÕES
     * ==========================================================
     */

    public function listarPublicacoes(): array
    {
        $sql = "
            SELECT
                p.*,
                u.nome AS autor_nome
            FROM publicacao p
            INNER JOIN usuario u
                ON u.id = p.usuario_id
            WHERE p.status = 'Ativo'
              AND (
                    p.categoria = 'Aviso_Sindico'
                    OR p.categoria = 'Aviso_Administrador'
                  )
            ORDER BY p.created_at DESC
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /*
     * ==========================================================
     * DOCUMENTOS
     * ==========================================================
     */

    public function listarDocumentos(): array
    {
        $sql = "
            SELECT
                d.*,
                u.nome AS usuario_nome
            FROM documento d
            LEFT JOIN usuario u
                ON u.id = d.usuario_upload_id
            ORDER BY d.data_upload DESC
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }
}