<?php

require_once __DIR__ . "/../../service/Sessao.php";

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PublicacaoController.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

$controller =
    new PublicacaoController();

if (
    isset($_GET["excluir"]) &&
    is_numeric($_GET["excluir"])
) {

    $controller->excluir(
        (int) $_GET["excluir"]
    );

    header(
        "Location: index.php"
    );

    exit;
}

$publicacoes =
    $controller->listar();

$categoriaFiltro = trim((string)($_GET['categoria'] ?? ''));
if ($categoriaFiltro !== '') {
    $publicacoes = array_values(array_filter($publicacoes, function ($publicacao) use ($categoriaFiltro) {
        return $publicacao->getCategoria() === $categoriaFiltro;
    }));
} else {
    // Achados e Perdidos tem aba própria: não aparece na lista geral de publicações.
    $publicacoes = array_values(array_filter($publicacoes, function ($publicacao) {
        return $publicacao->getCategoria() !== 'Achados_Perdidos';
    }));
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
        Publicações - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2>
            <?= $categoriaFiltro === 'Achados_Perdidos' ? '🔎 Achados e Perdidos' : 'Publicações' ?>
        </h2>

        <div>

            <?php if ($categoriaFiltro !== 'Achados_Perdidos'): ?>
                <a
                    href="cadastrar.php"
                    class="btn btn-primary"
                >
                    + Nova Publicação
                </a>
            <?php else: ?>
                <a
                    href="../portaria/achados_perdidos.php"
                    class="btn btn-primary"
                >
                    + Registrar achado / perdido
                </a>
            <?php endif; ?>

            <a
                href="../dashboard.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

        </div>

    </div>

    <div class="card">

        <div class="card-body">

            <div class="table-responsive">

                <table
                    class="table table-bordered table-hover align-middle"
                >

                    <thead class="table-dark">

                    <tr>

                        <th>Imagem</th>

                        <th>Categoria</th>

                        <th>Título</th>

                        <th>Conteúdo</th>

                        <th>Preço</th>

                        <th>Status</th>

                        <th>Autor</th>

                        <th>Data</th>

                        <th>Ações</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($publicacoes)): ?>

                        <tr>

                            <td
                                colspan="9"
                                class="text-center"
                            >
                                Nenhuma publicação cadastrada.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $publicacoes
                            as $publicacao
                        ): ?>

                            <tr>

                                <td>

                                    <?php if (
                                        $publicacao->getMidiaUrl()
                                    ): ?>

                                        <img
                                            src="<?= htmlspecialchars(
                                                $publicacao->getMidiaUrl()
                                            ) ?>"
                                            alt="Imagem"
                                            style="
                                                width:80px;
                                                height:60px;
                                                object-fit:cover;
                                            "
                                            class="rounded border"
                                        >

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Sem imagem
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php

                                    $categorias = [
                                        "Aviso_Sindico"
                                            => "Aviso do Síndico",

                                        "Feed_Social"
                                            => "Feed Social",

                                        "Classificados"
                                            => "Classificados",

                                        "Achados_Perdidos"
                                            => "Achados e Perdidos",

                                        "Enquete"
                                            => "Enquete"
                                    ];

                                    echo htmlspecialchars(
                                        $categorias[
                                            $publicacao->getCategoria()
                                        ]
                                        ??
                                        $publicacao->getCategoria()
                                    );

                                    ?>

                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $publicacao->getTitulo()
                                    ) ?>
                                </td>

                                <td>

                                    <?php

                                    $conteudo =
                                        $publicacao->getConteudo();

                                    if (
                                        mb_strlen($conteudo) > 80
                                    ) {
                                        $conteudo =
                                            mb_substr(
                                                $conteudo,
                                                0,
                                                80
                                            )
                                            . "...";
                                    }

                                    echo htmlspecialchars(
                                        $conteudo
                                    );

                                    ?>

                                </td>

                                <td>

                                    <?php if (
                                        $publicacao->getPreco()
                                        !== null
                                    ): ?>

                                        R$
                                        <?= number_format(
                                            $publicacao->getPreco(),
                                            2,
                                            ",",
                                            "."
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php

                                    $classeStatus =
                                        "bg-secondary";

                                    if (
                                        $publicacao->getStatus()
                                        === "Ativo"
                                    ) {
                                        $classeStatus =
                                            "bg-success";
                                    }

                                    if (
                                        $publicacao->getStatus()
                                        === "Encerrado"
                                    ) {
                                        $classeStatus =
                                            "bg-warning text-dark";
                                    }

                                    if (
                                        $publicacao->getStatus()
                                        === "Removido"
                                    ) {
                                        $classeStatus =
                                            "bg-danger";
                                    }

                                    ?>

                                    <span
                                        class="badge <?= $classeStatus ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $publicacao->getStatus()
                                        ) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $publicacao->getUsuarioNome()
                                        ?? "-"
                                    ) ?>
                                </td>

                                <td>

                                    <?php

                                    if (
                                        $publicacao->getCreatedAt()
                                    ) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $publicacao->getCreatedAt()
                                            )
                                        );

                                    } else {

                                        echo "-";
                                    }

                                    ?>

                                </td>

                                <td>

                                    <a
                                        href="editar.php?id=<?= $publicacao->getId() ?>"
                                        class="btn btn-sm btn-warning mb-1"
                                    >
                                        Editar
                                    </a>

                                    <a
                                        href="index.php?excluir=<?= $publicacao->getId() ?>"
                                        class="btn btn-sm btn-danger mb-1"
                                        onclick="return confirm('Tem certeza que deseja excluir esta publicação?');"
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
