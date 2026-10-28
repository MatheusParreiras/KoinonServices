<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";
require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$conexao = Conexao::conectar();

$controller = new AutorizacaoAcessoController();

$mensagem = "";
$tipoMensagem = "";


/*
 * ============================================================
 * BUSCA A UNIDADE DO MORADOR
 * ============================================================
 */

$unidade = null;

$sqlUnidade = "
    SELECT
        id,
        identificacao,
        tipo_unidade
    FROM unidade
    WHERE id = (
        SELECT unidade_id
        FROM usuario
        WHERE id = :usuario_id
        LIMIT 1
    )
    LIMIT 1
";

$stmtUnidade = $conexao->prepare(
    $sqlUnidade
);

$stmtUnidade->bindValue(
    ":usuario_id",
    $usuarioId,
    PDO::PARAM_INT
);

$stmtUnidade->execute();

$unidade = $stmtUnidade->fetch(
    PDO::FETCH_ASSOC
);


/*
 * ============================================================
 * BUSCA OS VISITANTES
 * ============================================================
 *
 * A tabela visitante não possui usuario_id.
 * Portanto, neste momento, o morador pode selecionar
 * os visitantes cadastrados no sistema.
 */

$visitantes = [];

$sqlVisitantes = "
    SELECT
        id,
        nome,
        documento,
        foto_url,
        tipo_visitante,
        created_at
    FROM visitante
    ORDER BY nome ASC
";

$stmtVisitantes = $conexao->prepare(
    $sqlVisitantes
);

$stmtVisitantes->execute();

$visitantes = $stmtVisitantes->fetchAll(
    PDO::FETCH_ASSOC
);


/*
 * ============================================================
 * VISITANTE CADASTRADO RECENTEMENTE
 * ============================================================
 */

$visitanteCadastrado =
    isset($_GET["visitante_cadastrado"])
    &&
    $_GET["visitante_cadastrado"] === "1";


/*
 * ============================================================
 * FUNÇÃO PARA MONTAR URL DA FOTO
 * ============================================================
 */

function urlFotoVisitante(
    ?string $foto
): ?string {

    if (
        empty($foto)
    ) {

        return null;
    }


    $foto = trim(
        $foto
    );


    if (
        $foto === ""
    ) {

        return null;
    }


    /*
     * URL completa.
     */
    if (
        str_starts_with(
            $foto,
            "http://"
        )
        ||
        str_starts_with(
            $foto,
            "https://"
        )
    ) {

        return $foto;
    }


    /*
     * Caminho já absoluto do projeto.
     */
    if (
        str_starts_with(
            $foto,
            "/koinon-service/"
        )
    ) {

        return $foto;
    }


    /*
     * Remove barras iniciais.
     */
    $foto = ltrim(
        $foto,
        "/"
    );


    /*
     * Remove ../
     */
    while (
        str_starts_with(
            $foto,
            "../"
        )
    ) {

        $foto = substr(
            $foto,
            3
        );
    }


    return "/koinon-service/"
        . ltrim(
            $foto,
            "/"
        );
}


/*
 * ============================================================
 * CADASTRO DA AUTORIZAÇÃO
 * ============================================================
 */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    $visitanteId = (int) (
        $_POST["visitante_id"] ?? 0
    );


    $dataInicioInput =
        trim(
            $_POST["data_inicio"] ?? ""
        );


    $dataFimInput =
        trim(
            $_POST["data_fim"] ?? ""
        );


    /*
     * Validação básica.
     */

    if (
        $visitanteId <= 0
    ) {

        $mensagem =
            "Selecione um visitante.";

        $tipoMensagem =
            "danger";

    } elseif (
        !$unidade
    ) {

        $mensagem =
            "Seu usuário não possui uma unidade vinculada.";

        $tipoMensagem =
            "danger";

    } elseif (
        $dataInicioInput === ""
        ||
        $dataFimInput === ""
    ) {

        $mensagem =
            "Informe a data e o horário de início e término.";

        $tipoMensagem =
            "danger";

    } else {

        try {

            /*
             * Converte datetime-local:
             *
             * 2026-09-21T15:30
             *
             * para:
             *
             * 2026-09-21 15:30:00
             */

            $dataInicio =
                str_replace(
                    "T",
                    " ",
                    $dataInicioInput
                );


            $dataFim =
                str_replace(
                    "T",
                    " ",
                    $dataFimInput
                );


            /*
             * Garante segundos.
             */

            if (
                strlen($dataInicio) === 16
            ) {

                $dataInicio .= ":00";
            }


            if (
                strlen($dataFim) === 16
            ) {

                $dataFim .= ":00";
            }


            $inicio =
                new DateTime(
                    $dataInicio
                );


            $fim =
                new DateTime(
                    $dataFim
                );


            $agora =
                new DateTime();


            /*
             * O fim precisa ser depois do início.
             */

            if (
                $fim <= $inicio
            ) {

                throw new Exception(
                    "A data/hora de término deve ser posterior à data/hora de início."
                );
            }


            /*
             * Não permite autorização terminando
             * antes do momento atual.
             */

            if (
                $fim <= $agora
            ) {

                throw new Exception(
                    "A data/hora de término deve ser futura."
                );
            }


            /*
             * Verifica se o visitante existe.
             */

            $stmtVisitante =
                $conexao->prepare("
                    SELECT
                        id
                    FROM visitante
                    WHERE id = :id
                    LIMIT 1
                ");

            $stmtVisitante->bindValue(
                ":id",
                $visitanteId,
                PDO::PARAM_INT
            );

            $stmtVisitante->execute();


            if (
                !$stmtVisitante->fetch()
            ) {

                throw new Exception(
                    "O visitante selecionado não foi encontrado."
                );
            }


            /*
             * Cria a autorização.
             *
             * O Controller gera o QR Code.
             */

            $sucesso =
                $controller->cadastrar(
                    $visitanteId,
                    (int) $unidade["id"],
                    $usuarioId,
                    $inicio->format(
                        "Y-m-d H:i:s"
                    ),
                    $fim->format(
                        "Y-m-d H:i:s"
                    )
                );


            if (
                $sucesso
            ) {

                header(
                    "Location: autorizacoes.php?sucesso=1"
                );

                exit;

            }


            throw new Exception(
                "Não foi possível cadastrar a autorização."
            );


        } catch (Throwable $e) {

            $mensagem =
                "Erro ao cadastrar autorização: "
                . Erro::mensagem($e);

            $tipoMensagem =
                "danger";
        }
    }
}


/*
 * ============================================================
 * VALORES DO FORMULÁRIO
 * ============================================================
 */

$visitanteSelecionado =
    (int) (
        $_POST["visitante_id"] ?? 0
    );


$dataInicioSelecionada =
    $_POST["data_inicio"] ?? "";


$dataFimSelecionada =
    $_POST["data_fim"] ?? "";


/*
 * Se acabou de cadastrar visitante,
 * tenta selecionar o último visitante cadastrado.
 *
 * Como o cadastro atual não envia o ID,
 * usamos o visitante mais recente.
 */

if (
    $visitanteCadastrado
    &&
    $visitanteSelecionado <= 0
) {

    $stmtUltimo =
        $conexao->prepare("
            SELECT id
            FROM visitante
            ORDER BY id DESC
            LIMIT 1
        ");

    $stmtUltimo->execute();

    $ultimo =
        $stmtUltimo->fetch(
            PDO::FETCH_ASSOC
        );


    if ($ultimo) {

        $visitanteSelecionado =
            (int) $ultimo["id"];
    }
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
        Nova Autorização - Koinon Services
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <style>

        body {
            min-height: 100vh;
        }


        .card-visitante {

            cursor: pointer;

            transition:
                transform 0.15s ease,
                box-shadow 0.15s ease;

            height: 100%;

        }


        .card-visitante:hover {

            transform: translateY(-3px);

            box-shadow:
                0 0.5rem 1rem
                rgba(0, 0, 0, 0.15);

        }


        .card-visitante.selecionado {

            border:
                3px solid #0d6efd;

        }


        .foto-visitante {

            width: 100%;

            height: 180px;

            object-fit: cover;

            background: #f1f1f1;

            border-radius:
                8px 8px 0 0;

        }


        .sem-foto {

            width: 100%;

            height: 180px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #f1f1f1;

            color: #777;

            border-radius:
                8px 8px 0 0;

        }


        .check-visitante {

            position: absolute;

            top: 10px;

            right: 10px;

            width: 30px;

            height: 30px;

            border-radius: 50%;

            background: #0d6efd;

            color: white;

            display: none;

            align-items: center;

            justify-content: center;

            font-weight: bold;

        }


        .card-visitante.selecionado
        .check-visitante {

            display: flex;

        }


        .tipo-badge {

            font-size: 11px;

        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<!-- ==========================================================
     NAVBAR
=========================================================== -->

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand fw-bold text-decoration-none"
        >

            🏢 Koinon Services

        </a>


        <a
            href="autorizacoes.php"
            class="btn btn-outline-light btn-sm"
        >

            ← Voltar

        </a>

    </div>

</nav>


<!-- ==========================================================
     CONTEÚDO
=========================================================== -->

<div class="container py-4">


    <!-- CABEÇALHO -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
    >

        <div>

            <h2 class="mb-1">

                👥 Nova autorização

            </h2>

            <p class="text-muted mb-0">

                Escolha o visitante e defina o período
                de acesso.

            </p>

        </div>


        <a
            href="cadastrar_visitante.php"
            class="btn btn-info text-white"
        >

            📷 Cadastrar novo visitante

        </a>

    </div>


    <!-- ======================================================
         MENSAGEM
    ======================================================= -->

    <?php if (
        $mensagem !== ""
    ): ?>

        <div
            class="alert alert-<?= htmlspecialchars(
                $tipoMensagem
            ) ?>"
        >

            <?= htmlspecialchars(
                $mensagem
            ) ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         VISITANTE CADASTRADO
    ======================================================= -->

    <?php if (
        $visitanteCadastrado
    ): ?>

        <div class="alert alert-success">

            ✅ Visitante cadastrado com sucesso!

            <br>

            O visitante mais recente já foi selecionado
            abaixo.

        </div>

    <?php endif; ?>


    <!-- ======================================================
         UNIDADE
    ======================================================= -->

    <?php if (
        $unidade
    ): ?>

        <div class="alert alert-light border">

            🏠

            <strong>
                Unidade:
            </strong>

            <?= htmlspecialchars(
                $unidade["identificacao"]
            ) ?>

            <?php if (
                !empty(
                    $unidade["tipo_unidade"]
                )
            ): ?>

                —

                <?= htmlspecialchars(
                    $unidade["tipo_unidade"]
                ) ?>

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="alert alert-danger">

            ⚠️

            Seu usuário não possui uma unidade vinculada.

            Entre em contato com a administração.

        </div>

    <?php endif; ?>


    <!-- ======================================================
         FORMULÁRIO
    ======================================================= -->

    <form
        method="POST"
        id="formAutorizacao"
    >


        <!-- ==================================================
             SELEÇÃO DO VISITANTE
        =================================================== -->

        <div class="card shadow-sm mb-4">


            <div
                class="card-header d-flex justify-content-between align-items-center"
            >

                <span>

                    👤 Escolha o visitante

                </span>


                <span
                    class="badge bg-primary"
                    id="contadorVisitantes"
                >

                    <?= count(
                        $visitantes
                    ) ?>

                    visitante(s)

                </span>

            </div>


            <div class="card-body">


                <?php if (
                    empty($visitantes)
                ): ?>


                    <div class="text-center py-5">


                        <div
                            style="font-size:60px;"
                        >

                            👤

                        </div>


                        <h4 class="mt-3">

                            Nenhum visitante cadastrado

                        </h4>


                        <p class="text-muted">

                            Cadastre o visitante antes
                            de criar uma autorização.

                        </p>


                        <a
                            href="cadastrar_visitante.php"
                            class="btn btn-primary"
                        >

                            📷 Cadastrar visitante

                        </a>


                    </div>


                <?php else: ?>


                    <!-- BUSCA -->

                    <div class="mb-4">

                        <label
                            for="buscarVisitante"
                            class="form-label"
                        >

                            🔎 Pesquisar visitante

                        </label>


                        <input
                            type="text"
                            id="buscarVisitante"
                            class="form-control"
                            placeholder="Digite o nome ou CPF..."
                        >

                    </div>


                    <!-- GRID -->

                    <div
                        class="row g-3"
                        id="listaVisitantes"
                    >


                        <?php foreach (
                            $visitantes
                            as $visitante
                        ): ?>


                            <?php

                            $idVisitante =
                                (int) $visitante["id"];


                            $foto =
                                urlFotoVisitante(
                                    $visitante["foto_url"]
                                    ?? null
                                );


                            $selecionado =
                                (
                                    $visitanteSelecionado
                                    ===
                                    $idVisitante
                                );

                            ?>


                            <div
                                class="col-md-6 col-lg-4 item-visitante"
                                data-nome="<?= htmlspecialchars(
                                    strtolower(
                                        $visitante["nome"]
                                    )
                                ) ?>"
                                data-documento="<?= htmlspecialchars(
                                    strtolower(
                                        Cpf::formatar($visitante["documento"])
                                    )
                                ) ?>"
                            >


                                <div
                                    class="card card-visitante position-relative <?= $selecionado ? "selecionado" : "" ?>"
                                    data-id="<?= $idVisitante ?>"
                                    onclick="selecionarVisitante(<?= $idVisitante ?>)"
                                >


                                    <div
                                        class="check-visitante"
                                    >

                                        ✓

                                    </div>


                                    <!-- FOTO -->

                                    <?php if (
                                        !empty($foto)
                                    ): ?>


                                        <img
                                            src="<?= htmlspecialchars(
                                                $foto
                                            ) ?>"
                                            alt="Foto do visitante"
                                            class="foto-visitante"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                        >


                                        <div
                                            class="sem-foto"
                                            style="display:none;"
                                        >

                                            📷 Foto não encontrada

                                        </div>


                                    <?php else: ?>


                                        <div class="sem-foto">

                                            📷 Sem foto

                                        </div>


                                    <?php endif; ?>


                                    <div class="card-body">


                                        <h5
                                            class="card-title"
                                        >

                                            <?= htmlspecialchars(
                                                $visitante["nome"]
                                            ) ?>

                                        </h5>


                                        <p class="mb-2">

                                            <strong>
                                                CPF:
                                            </strong>

                                            <?= htmlspecialchars(
                                                Cpf::formatar($visitante["documento"])
                                            ) ?>

                                        </p>


                                        <span
                                            class="badge bg-secondary tipo-badge"
                                        >

                                            <?= htmlspecialchars(
                                                $visitante["tipo_visitante"]
                                            ) ?>

                                        </span>


                                    </div>


                                </div>

                            </div>


                        <?php endforeach; ?>


                    </div>


                    <div
                        id="nenhumResultado"
                        class="alert alert-warning mt-3"
                        style="display:none;"
                    >

                        Nenhum visitante encontrado
                        para essa pesquisa.

                    </div>


                <?php endif; ?>


                <!-- CAMPO ESCONDIDO -->

                <input
                    type="hidden"
                    name="visitante_id"
                    id="visitante_id"
                    value="<?= $visitanteSelecionado ?>"
                >


            </div>

        </div>


        <!-- ==================================================
             PERÍODO
        =================================================== -->

        <?php if (
            !empty($visitantes)
            &&
            $unidade
        ): ?>


            <div class="card shadow-sm mb-4">


                <div class="card-header">

                    📅 Período da autorização

                </div>


                <div class="card-body">


                    <div class="row g-3">


                        <div class="col-md-6">


                            <label
                                for="data_inicio"
                                class="form-label"
                            >

                                Início do acesso

                            </label>


                            <input
                                type="datetime-local"
                                name="data_inicio"
                                id="data_inicio"
                                class="form-control"
                                required
                                value="<?= htmlspecialchars(
                                    $dataInicioSelecionada
                                ) ?>"
                            >


                            <div
                                class="form-text"
                            >

                                Data e horário a partir
                                dos quais o QR Code poderá
                                ser utilizado.

                            </div>


                        </div>


                        <div class="col-md-6">


                            <label
                                for="data_fim"
                                class="form-label"
                            >

                                Fim do acesso

                            </label>


                            <input
                                type="datetime-local"
                                name="data_fim"
                                id="data_fim"
                                class="form-control"
                                required
                                value="<?= htmlspecialchars(
                                    $dataFimSelecionada
                                ) ?>"
                            >


                            <div
                                class="form-text"
                            >

                                Depois deste horário,
                                o QR Code será considerado
                                expirado.

                            </div>


                        </div>


                    </div>


                    <div
                        class="alert alert-info mt-4 mb-0"
                    >

                        💡

                        O QR Code será gerado automaticamente
                        após a autorização ser cadastrada.

                    </div>


                </div>

            </div>


            <!-- ==================================================
                 AÇÕES
            =================================================== -->

            <div
                class="d-flex justify-content-between gap-2"
            >


                <a
                    href="autorizacoes.php"
                    class="btn btn-secondary"
                >

                    Cancelar

                </a>


                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >

                    🔐 Criar autorização

                </button>


            </div>


        <?php endif; ?>


    </form>


</div>


<script>

/*
 * ============================================================
 * ELEMENTOS
 * ============================================================
 */

const campoVisitante =
    document.getElementById(
        "visitante_id"
    );


const campoInicio =
    document.getElementById(
        "data_inicio"
    );


const campoFim =
    document.getElementById(
        "data_fim"
    );


const campoBusca =
    document.getElementById(
        "buscarVisitante"
    );


const form =
    document.getElementById(
        "formAutorizacao"
    );


/*
 * ============================================================
 * SELECIONAR VISITANTE
 * ============================================================
 */

function selecionarVisitante(
    id
) {

    if (
        !campoVisitante
    ) {

        return;
    }


    campoVisitante.value =
        id;


    /*
     * Remove seleção dos cards.
     */

    document
        .querySelectorAll(
            ".card-visitante"
        )
        .forEach(
            function(card) {

                card.classList.remove(
                    "selecionado"
                );

            }
        );


    /*
     * Seleciona o card clicado.
     */

    const card =
        document.querySelector(
            '.card-visitante[data-id="' +
            id +
            '"]'
        );


    if (card) {

        card.classList.add(
            "selecionado"
        );

    }

}


/*
 * ============================================================
 * PESQUISA DE VISITANTES
 * ============================================================
 */

if (
    campoBusca
) {

    campoBusca.addEventListener(
        "input",
        function() {

            const termo =
                this.value
                    .trim()
                    .toLowerCase();


            let encontrados =
                0;


            document
                .querySelectorAll(
                    ".item-visitante"
                )
                .forEach(
                    function(item) {

                        const nome =
                            item.dataset.nome
                            || "";


                        const documento =
                            item.dataset.documento
                            || "";


                        const corresponde =
                            termo === ""
                            ||
                            nome.includes(
                                termo
                            )
                            ||
                            documento.includes(
                                termo
                            );


                        if (
                            corresponde
                        ) {

                            item.style.display =
                                "";

                            encontrados++;

                        } else {

                            item.style.display =
                                "none";

                        }

                    }
                );


            const nenhum =
                document.getElementById(
                    "nenhumResultado"
                );


            if (
                nenhum
            ) {

                nenhum.style.display =
                    encontrados === 0
                    ? "block"
                    : "none";

            }

        }
    );

}


/*
 * ============================================================
 * DEFINE DATA MÍNIMA
 * ============================================================
 */

function formatarParaDatetimeLocal(
    data
) {

    const ano =
        data.getFullYear();


    const mes =
        String(
            data.getMonth() + 1
        ).padStart(
            2,
            "0"
        );


    const dia =
        String(
            data.getDate()
        ).padStart(
            2,
            "0"
        );


    const hora =
        String(
            data.getHours()
        ).padStart(
            2,
            "0"
        );


    const minuto =
        String(
            data.getMinutes()
        ).padStart(
            2,
            "0"
        );


    return (
        ano
        + "-"
        + mes
        + "-"
        + dia
        + "T"
        + hora
        + ":"
        + minuto
    );

}


/*
 * Se os campos estiverem vazios,
 * coloca o horário atual como início.
 */

if (
    campoInicio
    &&
    campoInicio.value === ""
) {

    const agora =
        new Date();


    agora.setSeconds(
        0,
        0
    );


    campoInicio.value =
        formatarParaDatetimeLocal(
            agora
        );

}


/*
 * Se o fim estiver vazio,
 * coloca 1 hora depois.
 */

if (
    campoFim
    &&
    campoFim.value === ""
) {

    const fim =
        new Date();


    fim.setHours(
        fim.getHours() + 1
    );


    fim.setSeconds(
        0,
        0
    );


    campoFim.value =
        formatarParaDatetimeLocal(
            fim
        );

}


/*
 * ============================================================
 * QUANDO ALTERAR O INÍCIO
 * ============================================================
 */

if (
    campoInicio
    &&
    campoFim
) {

    campoInicio.addEventListener(
        "change",
        function() {

            if (
                campoInicio.value === ""
            ) {

                return;
            }


            if (
                campoFim.value === ""
            ) {

                const inicio =
                    new Date(
                        campoInicio.value
                    );


                inicio.setHours(
                    inicio.getHours() + 1
                );


                campoFim.value =
                    formatarParaDatetimeLocal(
                        inicio
                    );

            }

        }
    );

}


/*
 * ============================================================
 * VALIDAÇÃO DO FORMULÁRIO
 * ============================================================
 */

if (
    form
) {

    form.addEventListener(
        "submit",
        function(event) {


            /*
             * Verifica visitante.
             */

            if (
                !campoVisitante
                ||
                parseInt(
                    campoVisitante.value
                ) <= 0
            ) {

                event.preventDefault();


                alert(
                    "Selecione um visitante."
                );


                return;
            }


            /*
             * Verifica datas.
             */

            if (
                campoInicio
                &&
                campoFim
            ) {

                const inicio =
                    new Date(
                        campoInicio.value
                    );


                const fim =
                    new Date(
                        campoFim.value
                    );


                if (
                    isNaN(
                        inicio.getTime()
                    )
                    ||
                    isNaN(
                        fim.getTime()
                    )
                ) {

                    event.preventDefault();


                    alert(
                        "Informe corretamente as datas e horários."
                    );


                    return;
                }


                if (
                    fim <= inicio
                ) {

                    event.preventDefault();


                    alert(
                        "O término deve ser posterior ao início."
                    );


                    return;
                }

            }


            /*
             * Confirmação final.
             */

            const confirmado =
                confirm(
                    "Deseja criar esta autorização de acesso?"
                );


            if (
                !confirmado
            ) {

                event.preventDefault();

            }

        }
    );

}


/*
 * ============================================================
 * SE EXISTIR VISITANTE SELECIONADO,
 * GARANTE O VISUAL DO CARD.
 * ============================================================
 */

if (
    campoVisitante
    &&
    parseInt(
        campoVisitante.value
    ) > 0
) {

    selecionarVisitante(
        parseInt(
            campoVisitante.value
        )
    );

}

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>