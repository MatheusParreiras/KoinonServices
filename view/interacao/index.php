<?php

require_once __DIR__ . "/../../controller/InteracaoController.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

$controller = new InteracaoController();

$mensagemSucesso =
    $_GET["sucesso"] ?? "";

$mensagemErro =
    $_GET["erro"] ?? "";


/*
|--------------------------------------------------------------------------
| EXCLUSÃO
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["excluir"])
    && is_numeric($_GET["excluir"])
) {

    $id = (int) $_GET["excluir"];

    if (
        $controller->excluir($id)
    ) {

        header(
            "Location: index.php?sucesso=Interação excluída com sucesso!"
        );

        exit;

    } else {

        header(
            "Location: index.php?erro=Não foi possível excluir a interação."
        );

        exit;
    }
}


$lista =
    $controller->listar();


/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function textoTipoInteracao(
    string $tipo
): string {

    return match ($tipo) {

        "Curtida"
            => "❤️ Curtida",

        "Comentario"
            => "💬 Comentário",

        "Voto_Enquete"
            => "🗳️ Voto em Enquete",

        default
            => $tipo
    };
}


function classeTipoInteracao(
    string $tipo
): string {

    return match ($tipo) {

        "Curtida"
            => "danger",

        "Comentario"
            => "primary",

        "Voto_Enquete"
            => "warning",

        default
            => "secondary"
    };
}


function textoCategoria(
    ?string $categoria
): string {

    return match ($categoria) {

        "Aviso_Sindico"
            => "Aviso do Síndico",

        "Feed_Social"
            => "Feed Social",

        "Classificados"
            => "Classificados",

        "Achados_Perdidos"
            => "Achados e Perdidos",

        "Enquete"
            => "Enquete",

        default
            => $categoria ?? "-"
    };
}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Interações - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container-fluid py-4">

    <!-- CABEÇALHO -->

    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h2>💬 Interações</h2>

            <p class="text-muted mb-0">
                Curtidas, comentários e votos das publicações.
            </p>

        </div>

        <div>

            <a
                href="../dashboard.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

            <a
                href="cadastrar.php"
                class="btn btn-primary"
            >
                + Nova Interação
            </a>

        </div>

    </div>


    <!-- SUCESSO -->

    <?php if (
        $mensagemSucesso !== ""
    ): ?>

        <div class="alert alert-success">

            <?= htmlspecialchars(
                $mensagemSucesso
            ) ?>

        </div>

    <?php endif; ?>


    <!-- ERRO -->

    <?php if (
        $mensagemErro !== ""
    ): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars(
                $mensagemErro
            ) ?>

        </div>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow-sm">

        <div class="card-body">

            <div class="table-responsive">

                <table
                    class="table table-hover align-middle"
                >

                    <thead class="table-dark">

                        <tr>

                            <th>Publicação</th>

                            <th>Categoria</th>

                            <th>Usuário</th>

                            <th>Tipo</th>

                            <th>Conteúdo</th>

                            <th>Data</th>

                            <th>Ações</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (
                        empty($lista)
                    ): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center text-muted py-4"
                            >

                                Nenhuma interação cadastrada.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $lista
                            as $interacao
                        ): ?>

                            <tr>

                                <!-- PUBLICAÇÃO -->

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $interacao->getPublicacaoTitulo()
                                            ?? "-"
                                        ) ?>

                                    </strong>

                                </td>


                                <!-- CATEGORIA -->

                                <td>

                                    <span
                                        class="badge text-bg-secondary"
                                    >

                                        <?= htmlspecialchars(
                                            textoCategoria(
                                                $interacao->getPublicacaoCategoria()
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- USUÁRIO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $interacao->getUsuarioNome()
                                        ?? "-"
                                    ) ?>

                                </td>


                                <!-- TIPO -->

                                <td>

                                    <span
                                        class="badge text-bg-<?= classeTipoInteracao(
                                            $interacao->getTipoInteracao()
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            textoTipoInteracao(
                                                $interacao->getTipoInteracao()
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- CONTEÚDO -->

                                <td
                                    style="max-width: 350px;"
                                >

                                    <?php if (
                                        $interacao->getTipoInteracao()
                                        === "Curtida"
                                    ): ?>

                                        <span
                                            class="text-muted"
                                        >
                                            —
                                        </span>

                                    <?php else: ?>

                                        <?= htmlspecialchars(
                                            $interacao->getConteudoTexto()
                                            ?? "-"
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <!-- DATA -->

                                <td>

                                    <?= $interacao->getCreatedAt()
                                        ? date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $interacao->getCreatedAt()
                                            )
                                        )
                                        : "-"
                                    ?>

                                </td>


                                <!-- AÇÕES -->

                                <td
                                    style="min-width: 150px;"
                                >

                                    <a
                                        href="editar.php?id=<?= $interacao->getId() ?>"
                                        class="btn btn-sm btn-primary mb-1"
                                    >
                                        Editar
                                    </a>


                                    <a
                                        href="index.php?excluir=<?= $interacao->getId() ?>"
                                        class="btn btn-sm btn-danger mb-1"
                                        onclick="
                                            return confirm(
                                                'Tem certeza que deseja excluir esta interação?'
                                            );
                                        "
                                    >
                                        Excluir
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>
<script src="../../assets/js/script.js"></script>

</body>

</html>