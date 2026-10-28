<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";
require_once __DIR__ . "/../../service/Configuracao.php";

PermissaoController::exigirPortaria();

$controller = new EncomendaController();

$encomendas = $controller->listar();

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
        Encomendas - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <a
            href="<?= htmlspecialchars(Configuracao::url("view/dashboard.php")) ?>"
            class="navbar-brand fw-bold text-decoration-none"
        >
            🏢 Koinon Services
        </a>

        <div class="d-flex align-items-center gap-2 koinon-nav-actions">
            <!-- Sino e tema são inseridos pelos controles globais. -->
        </div>

    </div>

</nav>


<div class="container py-4">


    <?php if (
        isset($_GET["sucesso"])
    ): ?>

        <?php if (
            $_GET["sucesso"] === "cadastrada"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda cadastrada com sucesso!

            </div>

        <?php elseif (
            $_GET["sucesso"] === "editada"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda atualizada com sucesso!

            </div>

        <?php elseif (
            $_GET["sucesso"] === "entregue"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda marcada como entregue!

            </div>

        <?php elseif (
            $_GET["sucesso"] === "devolvida"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda marcada como devolvida!

            </div>

        <?php elseif (
            $_GET["sucesso"] === "reaberta"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda reaberta para retirada!

            </div>

        <?php elseif (
            $_GET["sucesso"] === "excluida"
        ): ?>

            <div class="alert alert-success">

                ✅ Encomenda excluída com sucesso!

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <?php if (
        isset($_GET["erro"])
    ): ?>

        <div class="alert alert-danger">

            ❌ Não foi possível realizar a operação.

        </div>

    <?php endif; ?>


    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h2 class="mb-1">

                📦 Encomendas

            </h2>

            <p class="text-muted mb-0">

                Controle de recebimento e retirada de encomendas.

            </p>

        </div>


        <div class="d-flex gap-2">

            <a
                href="<?= htmlspecialchars(Configuracao::url("view/dashboard.php")) ?>"
                class="btn btn-outline-secondary"
            >
                ← Dashboard
            </a>

            <a
                href="cadastrar.php"
                class="btn btn-primary"
            >
                + Nova encomenda
            </a>

        </div>

    </div>


    <div class="card shadow-sm">

        <div class="card-body">

            <?php if (
                empty($encomendas)
            ): ?>

                <div class="text-center py-5">

                    <div
                        class="display-4 mb-3"
                    >
                        📦
                    </div>

                    <h5>
                        Nenhuma encomenda cadastrada
                    </h5>

                    <p class="text-muted">

                        Cadastre uma nova encomenda recebida pela portaria.

                    </p>

                    <a
                        href="cadastrar.php"
                        class="btn btn-primary"
                    >
                        Cadastrar encomenda
                    </a>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table
                        class="table table-bordered table-hover align-middle"
                    >

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Unidade
                                </th>

                                <th>
                                    Morador
                                </th>

                                <th>
                                    Descrição
                                </th>

                                <th>
                                    Rastreio
                                </th>

                                <th>
                                    Foto
                                </th>

                                <th>
                                    Recebimento
                                </th>

                                <th>
                                    Retirada
                                </th>

                                <th>
                                    Status
                                </th>

                                <th
                                    style="min-width: 260px;"
                                >
                                    Ações
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $encomendas
                            as $encomenda
                        ): ?>

                            <?php

                            $status =
                                $encomenda->getStatus();

                            $classeStatus = match (
                                $status
                            ) {

                                "Aguardando_Retirada"
                                    => "bg-warning text-dark",

                                "Entregue"
                                    => "bg-success",

                                "Devolvido"
                                    => "bg-secondary",

                                default
                                    => "bg-dark"
                            };


                            $textoStatus = match (
                                $status
                            ) {

                                "Aguardando_Retirada"
                                    => "Aguardando retirada",

                                "Entregue"
                                    => "Entregue",

                                "Devolvido"
                                    => "Devolvido",

                                default
                                    => $status
                            };


                            $foto =
                                $encomenda->getFotoUrl();

                            if (
                                !empty($foto)
                            ) {

                                if (
                                    str_starts_with(
                                        $foto,
                                        "../../"
                                    )
                                ) {

                                    $foto = str_replace(
                                        "../../",
                                        Configuracao::url(),
                                        $foto
                                    );

                                } elseif (
                                    !str_starts_with(
                                        $foto,
                                        "/"
                                    ) &&
                                    !str_starts_with(
                                        $foto,
                                        "http"
                                    )
                                ) {

                                    $foto =
                                        Configuracao::url() .
                                        ltrim(
                                            $foto,
                                            "/"
                                        );
                                }
                            }

                            ?>

                            <tr>

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $encomenda
                                                ->getUnidadeIdentificacao()
                                                ?? "-"
                                        ) ?>

                                    </strong>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $encomenda
                                            ->getUsuarioNome()
                                            ?? "Não informado"
                                    ) ?>

                                </td>


                                <td>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $encomenda
                                                ->getDescricao()
                                                ?? ""
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        !empty(
                                            $encomenda
                                                ->getCodigoRastreio()
                                        )
                                    ): ?>

                                        <span
                                            class="font-monospace"
                                        >

                                            <?= htmlspecialchars(
                                                $encomenda
                                                    ->getCodigoRastreio()
                                            ) ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="text-muted"
                                        >
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (
                                        !empty($foto)
                                    ): ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                $foto
                                            ) ?>"
                                            target="_blank"
                                        >

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $foto
                                                ) ?>"
                                                alt="Foto da encomenda"
                                                style="
                                                    width:70px;
                                                    height:70px;
                                                    object-fit:cover;
                                                    border-radius:8px;
                                                "
                                            >

                                        </a>

                                    <?php else: ?>

                                        <span
                                            class="text-muted"
                                        >
                                            Sem foto
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php

                                    $dataRecebimento =
                                        $encomenda
                                            ->getDataRecebimento();

                                    echo $dataRecebimento
                                        ? date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataRecebimento
                                            )
                                        )
                                        : "-";

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    $dataRetirada =
                                        $encomenda
                                            ->getDataRetirada();

                                    echo $dataRetirada
                                        ? date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataRetirada
                                            )
                                        )
                                        : "-";

                                    ?>

                                </td>


                                <td>

                                    <span
                                        class="badge <?= $classeStatus ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $textoStatus
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <div
                                        class="d-flex flex-wrap gap-1"
                                    >

                                        <?php if ($status !== "Entregue"): ?>
                                            <a
                                                href="editar.php?id=<?= (int)$encomenda->getId() ?>"
                                                class="btn btn-sm btn-warning"
                                            >
                                                Editar
                                            </a>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary align-self-center">✅ Entrega concluída</span>
                                        <?php endif; ?>


                                        <?php if (
                                            $status ===
                                            "Aguardando_Retirada"
                                        ): ?>

                                            <a
                                                href="marcar_entregue.php?id=<?= (int)
                                                    $encomenda->getId()
                                                ?>"
                                                class="btn btn-sm btn-success"
                                            >
                                                Entregar
                                            </a>

                                            <a
                                                href="alterar_status.php?id=<?= (int)
                                                    $encomenda->getId()
                                                ?>&status=Devolvido"
                                                class="btn btn-sm btn-secondary"
                                                onclick="return confirm('Deseja realmente marcar esta encomenda como devolvida?');"
                                            >
                                                Devolver
                                            </a>

                                        <?php elseif (
                                            $status ===
                                            "Entregue"
                                        ): ?>

                                        <?php elseif (
                                            $status ===
                                            "Devolvido"
                                        ): ?>

                                            <a
                                                href="alterar_status.php?id=<?= (int)
                                                    $encomenda->getId()
                                                ?>&status=Aguardando_Retirada"
                                                class="btn btn-sm btn-outline-primary"
                                                onclick="return confirm('Deseja reabrir esta encomenda para retirada?');"
                                            >
                                                Reabrir
                                            </a>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <div class="row g-3 mt-3">

        <div class="col-md-4">

            <div class="card border-warning">

                <div class="card-body">

                    <h6>
                        📦 Aguardando retirada
                    </h6>

                    <h3>

                        <?= count(
                            array_filter(
                                $encomendas,
                                fn($e) =>
                                    $e->getStatus()
                                    === "Aguardando_Retirada"
                            )
                        ) ?>

                    </h3>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-success">

                <div class="card-body">

                    <h6>
                        ✅ Entregues
                    </h6>

                    <h3>

                        <?= count(
                            array_filter(
                                $encomendas,
                                fn($e) =>
                                    $e->getStatus()
                                    === "Entregue"
                            )
                        ) ?>

                    </h3>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-secondary">

                <div class="card-body">

                    <h6>
                        ↩️ Devolvidas
                    </h6>

                    <h3>

                        <?= count(
                            array_filter(
                                $encomendas,
                                fn($e) =>
                                    $e->getStatus()
                                    === "Devolvido"
                            )
                        ) ?>

                    </h3>

                </div>

            </div>

        </div>

    </div>


</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>