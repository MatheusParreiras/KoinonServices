<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/DocumentoController.php";

$controller = new DocumentoController();

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
            "Location: index.php?sucesso=Documento excluído com sucesso!"
        );

        exit;

    } else {

        header(
            "Location: index.php?erro=Não foi possível excluir o documento."
        );

        exit;
    }
}


$lista =
    $controller->listar();


function nomeCategoria(
    string $categoria
): string {

    return match ($categoria) {

        "Atas"
            => "Atas",

        "Convenção"
            => "Convenção",

        "Regimento_Interno"
            => "Regimento Interno",

        "Prestação_Contas"
            => "Prestação de Contas",

        "Contrato"
            => "Contrato",

        "Outros"
            => "Outros",

        default
            => $categoria
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
        Documentos - Koinon Services
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

            <h2>📄 Documentos</h2>

            <p class="text-muted mb-0">
                Atas, convenções, contratos e documentos do condomínio.
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
                + Novo Documento
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

                            <th>Título</th>

                            <th>Categoria</th>

                            <th>Descrição</th>

                            <th>Arquivo</th>

                            <th>Enviado por</th>

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

                                Nenhum documento cadastrado.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $lista
                            as $documento
                        ): ?>

                            <tr>


                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $documento->getTitulo()
                                        ) ?>

                                    </strong>

                                </td>


                                <td>

                                    <span
                                        class="badge text-bg-secondary"
                                    >

                                        <?= htmlspecialchars(
                                            nomeCategoria(
                                                $documento->getCategoria()
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <td
                                    style="max-width: 300px;"
                                >

                                    <?php

                                    $descricao =
                                        $documento->getDescricao();

                                    if (
                                        $descricao === null
                                        ||
                                        trim($descricao) === ""
                                    ) {

                                        echo '<span class="text-muted">-</span>';

                                    } elseif (
                                        strlen($descricao) > 80
                                    ) {

                                        echo htmlspecialchars(
                                            substr(
                                                $descricao,
                                                0,
                                                80
                                            )
                                        );

                                        echo "...";

                                    } else {

                                        echo htmlspecialchars(
                                            $descricao
                                        );
                                    }

                                    ?>

                                </td>


                                <td>

                                    <a
                                        href="<?= htmlspecialchars(
                                            $documento->getArquivoUrl()
                                        ) ?>"
                                        target="_blank"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        📄 Abrir PDF
                                    </a>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $documento->getUsuarioNome()
                                        ?? "-"
                                    ) ?>

                                </td>


                                <td>

                                    <?= $documento->getDataUpload()
                                        ? date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $documento->getDataUpload()
                                            )
                                        )
                                        : "-"
                                    ?>

                                </td>


                                <td
                                    style="min-width: 160px;"
                                >

                                    <a
                                        href="editar.php?id=<?= $documento->getId() ?>"
                                        class="btn btn-sm btn-primary mb-1"
                                    >
                                        Editar
                                    </a>

                                    <a
                                        href="index.php?excluir=<?= $documento->getId() ?>"
                                        class="btn btn-sm btn-danger mb-1"
                                        onclick="
                                            return confirm(
                                                'Tem certeza que deseja excluir este documento?'
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