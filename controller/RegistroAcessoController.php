<?php

require_once __DIR__ . "/../model/dao/RegistroAcessoDAO.php";
require_once __DIR__ . "/../model/dto/RegistroAcessoDTO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dao/Conexao.php";

class RegistroAcessoController
{
    private RegistroAcessoDAO $dao;

    private NotificacaoService $notificacao;

    public function __construct()
    {
        $this->dao =
            new RegistroAcessoDAO();

        $this->notificacao =
            new NotificacaoService();
    }

    /**
     * Lista todos os registros.
     */
    public function listar(): array
    {
        return $this->dao->listar();
    }

    /**
     * Lista registros utilizando filtros.
     */
    public function listarComFiltros(
        ?string $dataInicial = null,
        ?string $dataFinal = null,
        ?string $direcao = null,
        ?string $metodoLeitura = null
    ): array {

        return $this->dao->listarComFiltros(
            $dataInicial,
            $dataFinal,
            $direcao,
            $metodoLeitura
        );
    }

    /**
     * Busca um registro por ID.
     */
    public function buscarPorId(
        int $id
    ): ?RegistroAcessoDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }

    /**
     * Cadastra um registro de acesso.
     *
     * A notificação do morador continua
     * funcionando como já estava.
     */
    public function cadastrar(
        RegistroAcessoDTO $registro
    ): bool {

        $sucesso =
            $this->dao->cadastrar(
                $registro
            );

        if ($sucesso) {

            try {

                $conexao =
                    Conexao::conectar();

                $sql = "
                    SELECT
                        a.usuario_autorizador_id,
                        v.nome AS visitante_nome

                    FROM autorizacao_acesso a

                    INNER JOIN visitante v
                        ON v.id = a.visitante_id

                    WHERE a.visitante_id = :visitante_id

                      AND a.status = 'Ativo'

                      AND a.data_inicio <= NOW()

                      AND a.data_fim >= NOW()

                    ORDER BY a.id DESC

                    LIMIT 1
                ";

                $stmt =
                    $conexao->prepare(
                        $sql
                    );

                $stmt->bindValue(
                    ":visitante_id",
                    $registro->getVisitanteId(),
                    PDO::PARAM_INT
                );

                $stmt->execute();

                $autorizacao =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if ($autorizacao) {

                    $usuarioAutorizador =
                        $autorizacao[
                            "usuario_autorizador_id"
                        ];

                    /*
                     * Só envia notificação se
                     * existir um usuário autorizador.
                     */
                    if (
                        $usuarioAutorizador !== null &&
                        $usuarioAutorizador !== ""
                    ) {

                        $this->notificacao->visitanteEntrou(
                            (int) $usuarioAutorizador,
                            $autorizacao[
                                "visitante_nome"
                            ] ?? "Visitante"
                        );
                    }
                }

            } catch (Throwable $e) {

                /*
                 * A falha da notificação
                 * não impede o registro do acesso.
                 */
            }
        }

        return $sucesso;
    }

    /**
     * Edita um registro.
     */
    public function editar(
        RegistroAcessoDTO $registro
    ): bool {

        return $this->dao->editar(
            $registro
        );
    }

    /**
     * Exclui um registro.
     */
    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir(
            $id
        );
    }
}