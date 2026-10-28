<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/RegistroAcessoDTO.php";

class RegistroAcessoDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    public function listar(): array
    {
        return $this->listarComFiltros(
            null,
            null,
            null,
            null
        );
    }

    public function listarComFiltros(
        ?string $dataInicial = null,
        ?string $dataFinal = null,
        ?string $direcao = null,
        ?string $metodoLeitura = null
    ): array {

        $sql = "
            SELECT
                r.*,
                u.nome AS usuario_nome,
                u.cpf AS usuario_cpf,
                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.tipo_visitante AS visitante_tipo,
                r.autorizado_por_usuario_id,
                ap.nome AS autorizador_nome,
                ap.email AS autorizador_login,
                ap.perfil AS autorizador_perfil
            FROM registro_acesso r

            LEFT JOIN usuario u
                ON r.usuario_id = u.id

            LEFT JOIN visitante v
                ON r.visitante_id = v.id

            LEFT JOIN usuario ap
                ON r.autorizado_por_usuario_id = ap.id

            WHERE 1 = 1
        ";

        $parametros = [];

        if (
            $dataInicial !== null &&
            $dataInicial !== ""
        ) {

            $sql .= "
                AND DATE(r.data_hora) >= :data_inicial
            ";

            $parametros[":data_inicial"] =
                $dataInicial;
        }

        if (
            $dataFinal !== null &&
            $dataFinal !== ""
        ) {

            $sql .= "
                AND DATE(r.data_hora) <= :data_final
            ";

            $parametros[":data_final"] =
                $dataFinal;
        }

        if (
            $direcao !== null &&
            $direcao !== ""
        ) {

            $sql .= "
                AND r.direcao = :direcao
            ";

            $parametros[":direcao"] =
                $direcao;
        }

        if (
            $metodoLeitura !== null &&
            $metodoLeitura !== ""
        ) {

            $sql .= "
                AND r.metodo_leitura = :metodo_leitura
            ";

            $parametros[":metodo_leitura"] =
                $metodoLeitura;
        }

        $sql .= "
            ORDER BY r.data_hora DESC, r.id DESC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        foreach (
            $parametros as $chave => $valor
        ) {

            $stmt->bindValue(
                $chave,
                $valor
            );
        }

        $stmt->execute();

        $registros = [];

        while (
            $dados =
            $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $registro =
                $this->montarDTO($dados);

            /*
             * Os nomes são armazenados no DTO
             * apenas para exibição.
             *
             * Os IDs continuam existindo no banco,
             * mas não precisam aparecer para o usuário.
             */
            $registro->setUsuarioNome(
                $dados["usuario_nome"] ?? null
            );

            $registro->setUsuarioCpf(
                $dados["usuario_cpf"] ?? null
            );

            $registro->setVisitanteNome(
                $dados["visitante_nome"] ?? null
            );

            $registro->setVisitanteDocumento(
                $dados["visitante_documento"] ?? null
            );

            $registro->setVisitanteTipo(
                $dados["visitante_tipo"] ?? null
            );

            $registro->setAutorizadorNome(
                $dados["autorizador_nome"] ?? null
            );

            $registro->setAutorizadoPorUsuarioId(
                isset($dados["autorizado_por_usuario_id"]) && $dados["autorizado_por_usuario_id"] !== null
                    ? (int) $dados["autorizado_por_usuario_id"]
                    : null
            );

            $registro->setAutorizadorPerfil(
                $dados["autorizador_perfil"] ?? null
            );

            $registro->setAutorizadorLogin(
                $dados["autorizador_login"] ?? null
            );

            $registros[]=
                $registro;
        }

        return $registros;
    }

    public function buscarPorId(
        int $id
    ): ?RegistroAcessoDTO {

        $sql = "
            SELECT
                r.*,
                u.nome AS usuario_nome,
                u.cpf AS usuario_cpf,
                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.tipo_visitante AS visitante_tipo,
                r.autorizado_por_usuario_id,
                ap.nome AS autorizador_nome,
                ap.email AS autorizador_login,
                ap.perfil AS autorizador_perfil
            FROM registro_acesso r

            LEFT JOIN usuario u
                ON r.usuario_id = u.id

            LEFT JOIN visitante v
                ON r.visitante_id = v.id

            LEFT JOIN usuario ap
                ON r.autorizado_por_usuario_id = ap.id

            WHERE r.id = :id

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
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        $registro =
            $this->montarDTO($dados);

        $registro->setUsuarioNome(
            $dados["usuario_nome"] ?? null
        );

        $registro->setUsuarioCpf(
            $dados["usuario_cpf"] ?? null
        );

        $registro->setVisitanteNome(
            $dados["visitante_nome"] ?? null
        );

        $registro->setVisitanteDocumento(
            $dados["visitante_documento"] ?? null
        );

        $registro->setVisitanteTipo(
            $dados["visitante_tipo"] ?? null
        );

        $registro->setAutorizadorNome(
            $dados["autorizador_nome"] ?? null
        );

        $registro->setAutorizadoPorUsuarioId(
            isset($dados["autorizado_por_usuario_id"]) && $dados["autorizado_por_usuario_id"] !== null
                ? (int) $dados["autorizado_por_usuario_id"]
                : null
        );

        $registro->setAutorizadorPerfil(
            $dados["autorizador_perfil"] ?? null
        );

        $registro->setAutorizadorLogin(
            $dados["autorizador_login"] ?? null
        );

        return $registro;
    }

    public function cadastrar(
        RegistroAcessoDTO $registro
    ): bool {

        /*
         * Quando data_hora não for informada,
         * usamos CURRENT_TIMESTAMP do banco.
         */

        if (
            $registro->getDataHora() === null ||
            trim($registro->getDataHora()) === ""
        ) {

            $sql = "
                INSERT INTO registro_acesso (
                    usuario_id,
                    visitante_id,
                    autorizado_por_usuario_id,
                    direcao,
                    metodo_leitura,
                    foto_evento_url,
                    observacao
                )
                VALUES (
                    :usuario_id,
                    :visitante_id,
                    :autorizado_por_usuario_id,
                    :direcao,
                    :metodo_leitura,
                    :foto_evento_url,
                    :observacao
                )
            ";

        } else {

            $sql = "
                INSERT INTO registro_acesso (
                    usuario_id,
                    visitante_id,
                    autorizado_por_usuario_id,
                    data_hora,
                    direcao,
                    metodo_leitura,
                    foto_evento_url,
                    observacao
                )
                VALUES (
                    :usuario_id,
                    :visitante_id,
                    :autorizado_por_usuario_id,
                    :data_hora,
                    :direcao,
                    :metodo_leitura,
                    :foto_evento_url,
                    :observacao
                )
            ";
        }

        $stmt =
            $this->conexao->prepare($sql);

        $usuarioId =
            $registro->getUsuarioId();

        if ($usuarioId === null) {

            $stmt->bindValue(
                ":usuario_id",
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ":usuario_id",
                $usuarioId,
                PDO::PARAM_INT
            );
        }

        $visitanteId =
            $registro->getVisitanteId();

        $autorizadoPor = $registro->getAutorizadoPorUsuarioId();
        $stmt->bindValue(
            ":autorizado_por_usuario_id",
            $autorizadoPor,
            $autorizadoPor === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );

        if ($visitanteId === null) {

            $stmt->bindValue(
                ":visitante_id",
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ":visitante_id",
                $visitanteId,
                PDO::PARAM_INT
            );
        }

        if (
            $registro->getDataHora() !== null &&
            trim($registro->getDataHora()) !== ""
        ) {

            $stmt->bindValue(
                ":data_hora",
                $registro->getDataHora()
            );
        }

        $stmt->bindValue(
            ":direcao",
            $registro->getDirecao()
        );

        $stmt->bindValue(
            ":metodo_leitura",
            $registro->getMetodoLeitura()
        );

        $stmt->bindValue(
            ":foto_evento_url",
            $registro->getFotoEventoUrl()
        );

        $stmt->bindValue(
            ":observacao",
            $registro->getObservacao()
        );

        return $stmt->execute();
    }

    public function editar(
        RegistroAcessoDTO $registro
    ): bool {

        $sql = "
            UPDATE registro_acesso
            SET
                usuario_id = :usuario_id,
                visitante_id = :visitante_id,
                autorizado_por_usuario_id = :autorizado_por_usuario_id,
                data_hora = :data_hora,
                direcao = :direcao,
                metodo_leitura = :metodo_leitura,
                foto_evento_url = :foto_evento_url,
                observacao = :observacao
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $usuarioId =
            $registro->getUsuarioId();

        if ($usuarioId === null) {

            $stmt->bindValue(
                ":usuario_id",
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ":usuario_id",
                $usuarioId,
                PDO::PARAM_INT
            );
        }

        $visitanteId =
            $registro->getVisitanteId();

        if ($visitanteId === null) {

            $stmt->bindValue(
                ":visitante_id",
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ":visitante_id",
                $visitanteId,
                PDO::PARAM_INT
            );
        }

        $autorizadoPor = $registro->getAutorizadoPorUsuarioId();
        $stmt->bindValue(
            ":autorizado_por_usuario_id",
            $autorizadoPor,
            $autorizadoPor === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":data_hora",
            $registro->getDataHora()
        );

        $stmt->bindValue(
            ":direcao",
            $registro->getDirecao()
        );

        $stmt->bindValue(
            ":metodo_leitura",
            $registro->getMetodoLeitura()
        );

        $stmt->bindValue(
            ":foto_evento_url",
            $registro->getFotoEventoUrl()
        );

        $stmt->bindValue(
            ":observacao",
            $registro->getObservacao()
        );

        $stmt->bindValue(
            ":id",
            $registro->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    public function excluir(
        int $id
    ): bool {

        $sql = "
            DELETE FROM registro_acesso
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    private function montarDTO(
        array $dados
    ): RegistroAcessoDTO {

        $registro =
            new RegistroAcessoDTO();

        $registro->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $registro->setUsuarioId(
            isset($dados["usuario_id"]) &&
            $dados["usuario_id"] !== null
                ? (int) $dados["usuario_id"]
                : null
        );

        $registro->setVisitanteId(
            isset($dados["visitante_id"]) &&
            $dados["visitante_id"] !== null
                ? (int) $dados["visitante_id"]
                : null
        );

        $registro->setUsuarioNome(
            $dados["usuario_nome"] ?? null
        );

        $registro->setUsuarioCpf(
            $dados["usuario_cpf"] ?? null
        );

        $registro->setVisitanteNome(
            $dados["visitante_nome"] ?? null
        );

        $registro->setVisitanteDocumento(
            $dados["visitante_documento"] ?? null
        );

        $registro->setVisitanteTipo(
            $dados["visitante_tipo"] ?? null
        );

        $registro->setDataHora(
            $dados["data_hora"] ?? null
        );

        $registro->setDirecao(
            $dados["direcao"] ?? "Entrada"
        );

        $registro->setMetodoLeitura(
            $dados["metodo_leitura"] ?? "Manual"
        );

        $registro->setFotoEventoUrl(
            $dados["foto_evento_url"] ?? null
        );

        $registro->setObservacao(
            $dados["observacao"] ?? null
        );

        return $registro;
    }
}