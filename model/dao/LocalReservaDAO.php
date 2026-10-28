<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/LocalReservaDTO.php";

class LocalReservaDAO
{
    private PDO $conexao;


    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }


    /*
     * ==========================================================
     * LISTAR
     * ==========================================================
     */

    public function listar(
        bool $somenteAtivos = false
    ): array {

        /*
         * ------------------------------------------------------
         * IMPORTANTE
         * ------------------------------------------------------
         *
         * A listagem geral continua trazendo todos os locais.
         *
         * Quando somenteAtivos = true, fazemos a filtragem
         * depois de montar o DTO. Isso evita problemas caso
         * o campo "ativo" esteja armazenado no banco como
         * inteiro, booleano ou outro formato compatível.
         */

        $sql = "
            SELECT *
            FROM local_reserva
            ORDER BY
                nome ASC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute();

        $locais = [];

        while (
            $dados =
                $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $local =
                $this->montarDTO(
                    $dados
                );

            /*
             * --------------------------------------------------
             * FILTRO DE ATIVOS
             * --------------------------------------------------
             */

            if (
                $somenteAtivos &&
                !$local->getAtivo()
            ) {

                continue;
            }

            $locais[] =
                $local;
        }

        /*
         * ------------------------------------------------------
         * ADMIN
         * ------------------------------------------------------
         *
         * Mantém os ativos primeiro quando a listagem geral
         * for utilizada.
         */

        if (!$somenteAtivos) {

            usort(
                $locais,
                function (
                    LocalReservaDTO $a,
                    LocalReservaDTO $b
                ) {

                    if (
                        $a->getAtivo() ===
                        $b->getAtivo()
                    ) {

                        return strcasecmp(
                            $a->getNome(),
                            $b->getNome()
                        );
                    }

                    return
                        $a->getAtivo()
                            ? -1
                            : 1;
                }
            );
        }

        return $locais;
    }


    /*
     * ==========================================================
     * LISTAR SOMENTE ATIVOS
     * ==========================================================
     */

    public function listarAtivos(): array
    {
        return $this->listar(true);
    }


    /*
     * ==========================================================
     * BUSCAR POR ID
     * ==========================================================
     */

    public function buscarPorId(
        int $id
    ): ?LocalReservaDTO {

        $sql = "
            SELECT *
            FROM local_reserva
            WHERE id = :id
            LIMIT 1
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$dados) {
            return null;
        }

        return $this->montarDTO(
            $dados
        );
    }


    /*
     * ==========================================================
     * VERIFICAR NOME
     * ==========================================================
     */

    public function nomeExiste(
        string $nome,
        ?int $idIgnorar = null
    ): bool {

        if ($idIgnorar !== null) {

            $sql = "
                SELECT id
                FROM local_reserva
                WHERE LOWER(
                    TRIM(nome)
                ) =
                LOWER(
                    TRIM(:nome)
                )
                AND id <> :id
                LIMIT 1
            ";

            $stmt =
                $this->conexao->prepare(
                    $sql
                );

            $stmt->bindValue(
                ":nome",
                $nome,
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":id",
                $idIgnorar,
                PDO::PARAM_INT
            );

        } else {

            $sql = "
                SELECT id
                FROM local_reserva
                WHERE LOWER(
                    TRIM(nome)
                ) =
                LOWER(
                    TRIM(:nome)
                )
                LIMIT 1
            ";

            $stmt =
                $this->conexao->prepare(
                    $sql
                );

            $stmt->bindValue(
                ":nome",
                $nome,
                PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (bool)
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );
    }


    /*
     * ==========================================================
     * CADASTRAR
     * ==========================================================
     */

    public function cadastrar(
        LocalReservaDTO $local
    ): bool {

        $sql = "
            INSERT INTO local_reserva (

                nome,
                descricao,
                capacidade,
                valor,
                gratuito,

                duracao_minutos_minima,
                duracao_minutos_maxima,

                horario_inicio,
                horario_fim,

                antecedencia_min_dias,
                antecedencia_max_dias,

                limite_reservas_unidade,
                periodo_limite,

                domingo,
                segunda,
                terca,
                quarta,
                quinta,
                sexta,
                sabado,

                ativo

            )
            VALUES (

                :nome,
                :descricao,
                :capacidade,
                :valor,
                :gratuito,

                :duracao_minutos_minima,
                :duracao_minutos_maxima,

                :horario_inicio,
                :horario_fim,

                :antecedencia_min_dias,
                :antecedencia_max_dias,

                :limite_reservas_unidade,
                :periodo_limite,

                :domingo,
                :segunda,
                :terca,
                :quarta,
                :quinta,
                :sexta,
                :sabado,

                :ativo

            )
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $this->bindDados(
            $stmt,
            $local
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * EDITAR
     * ==========================================================
     */

    public function editar(
        LocalReservaDTO $local
    ): bool {

        $sql = "
            UPDATE local_reserva
            SET

                nome = :nome,

                descricao = :descricao,

                capacidade = :capacidade,

                valor = :valor,

                gratuito = :gratuito,

                duracao_minutos_minima =
                    :duracao_minutos_minima,

                duracao_minutos_maxima =
                    :duracao_minutos_maxima,

                horario_inicio =
                    :horario_inicio,

                horario_fim =
                    :horario_fim,

                antecedencia_min_dias =
                    :antecedencia_min_dias,

                antecedencia_max_dias =
                    :antecedencia_max_dias,

                limite_reservas_unidade =
                    :limite_reservas_unidade,

                periodo_limite =
                    :periodo_limite,

                domingo = :domingo,

                segunda = :segunda,

                terca = :terca,

                quarta = :quarta,

                quinta = :quinta,

                sexta = :sexta,

                sabado = :sabado,

                ativo = :ativo

            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $this->bindDados(
            $stmt,
            $local
        );

        $stmt->bindValue(
            ":id",
            $local->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * ATUALIZAR FOTOS
     * ==========================================================
     */

    public function atualizarFotos(int $id, array $fotos): bool
    {
        $sql = "
            UPDATE local_reserva
            SET fotos = :fotos
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->bindValue(':fotos', json_encode(array_values($fotos), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * DESATIVAR
     * ==========================================================
     */

    public function desativar(
        int $id
    ): bool {

        $sql = "
            UPDATE local_reserva
            SET ativo = 0
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * REATIVAR
     * ==========================================================
     */

    public function reativar(
        int $id
    ): bool {

        $sql = "
            UPDATE local_reserva
            SET ativo = 1
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare(
                $sql
            );

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * BIND DOS DADOS
     * ==========================================================
     */

    private function bindDados(
        PDOStatement $stmt,
        LocalReservaDTO $local
    ): void {

        $stmt->bindValue(
            ":nome",
            $local->getNome(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $local->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":capacidade",
            $local->getCapacidade(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":valor",
            $local->getValor()
        );

        $stmt->bindValue(
            ":gratuito",
            $local->getGratuito()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":duracao_minutos_minima",
            $local->getDuracaoMinutosMinima(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":duracao_minutos_maxima",
            $local->getDuracaoMinutosMaxima(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":horario_inicio",
            $local->getHorarioInicio(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":horario_fim",
            $local->getHorarioFim(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":antecedencia_min_dias",
            $local->getAntecedenciaMinDias(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":antecedencia_max_dias",
            $local->getAntecedenciaMaxDias(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":limite_reservas_unidade",
            $local->getLimiteReservasUnidade(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":periodo_limite",
            $local->getPeriodoLimite(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":domingo",
            $local->getDomingo()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":segunda",
            $local->getSegunda()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":terca",
            $local->getTerca()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":quarta",
            $local->getQuarta()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":quinta",
            $local->getQuinta()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":sexta",
            $local->getSexta()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":sabado",
            $local->getSabado()
                ? 1
                : 0,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":ativo",
            $local->getAtivo()
                ? 1
                : 0,
            PDO::PARAM_INT
        );
    }


    /*
     * ==========================================================
     * MONTAR DTO
     * ==========================================================
     */

    private function montarDTO(
        array $dados
    ): LocalReservaDTO {

        $local =
            new LocalReservaDTO();

        $local->setFotos(
            $dados["fotos"] ?? null
        );

        $local->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $local->setNome(
            $dados["nome"] ?? ""
        );

        $local->setDescricao(
            $dados["descricao"] ?? ""
        );

        $local->setCapacidade(
            isset($dados["capacidade"])
                ? (int) $dados["capacidade"]
                : 1
        );

        $local->setValor(
            isset($dados["valor"])
                ? (float) $dados["valor"]
                : 0.00
        );

        $local->setGratuito(
            (bool) (
                $dados["gratuito"]
                ?? 0
            )
        );

        $local->setDuracaoMinutosMinima(
            isset(
                $dados[
                    "duracao_minutos_minima"
                ]
            )
                ? (int) $dados[
                    "duracao_minutos_minima"
                ]
                : 60
        );

        $local->setDuracaoMinutosMaxima(
            isset(
                $dados[
                    "duracao_minutos_maxima"
                ]
            )
                ? (int) $dados[
                    "duracao_minutos_maxima"
                ]
                : 1440
        );

        $local->setHorarioInicio(
            $dados["horario_inicio"]
                ?? "08:00:00"
        );

        $local->setHorarioFim(
            $dados["horario_fim"]
                ?? "23:00:00"
        );

        $local->setAntecedenciaMinDias(
            isset(
                $dados[
                    "antecedencia_min_dias"
                ]
            )
                ? (int) $dados[
                    "antecedencia_min_dias"
                ]
                : 0
        );

        $local->setAntecedenciaMaxDias(
            isset(
                $dados[
                    "antecedencia_max_dias"
                ]
            )
                ? (int) $dados[
                    "antecedencia_max_dias"
                ]
                : 30
        );

        $local->setLimiteReservasUnidade(
            isset(
                $dados[
                    "limite_reservas_unidade"
                ]
            )
                ? (int) $dados[
                    "limite_reservas_unidade"
                ]
                : 1
        );

        $local->setPeriodoLimite(
            $dados["periodo_limite"]
                ?? "Dia"
        );

        $local->setDomingo(
            (bool) (
                $dados["domingo"]
                ?? 0
            )
        );

        $local->setSegunda(
            (bool) (
                $dados["segunda"]
                ?? 1
            )
        );

        $local->setTerca(
            (bool) (
                $dados["terca"]
                ?? 1
            )
        );

        $local->setQuarta(
            (bool) (
                $dados["quarta"]
                ?? 1
            )
        );

        $local->setQuinta(
            (bool) (
                $dados["quinta"]
                ?? 1
            )
        );

        $local->setSexta(
            (bool) (
                $dados["sexta"]
                ?? 1
            )
        );

        $local->setSabado(
            (bool) (
                $dados["sabado"]
                ?? 1
            )
        );

        $local->setAtivo(
            (bool) (
                $dados["ativo"]
                ?? 1
            )
        );

        $local->setCreatedAt(
            $dados["created_at"]
                ?? null
        );

        $local->setUpdatedAt(
            $dados["updated_at"]
                ?? null
        );

        return $local;
    }
}