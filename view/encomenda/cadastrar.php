<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";
require_once __DIR__ . "/../../model/dto/EncomendaDTO.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPortaria();

$controller = new EncomendaController();

$unidades = $controller->listarUnidades();

$erro = null;


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $unidadeId =
            (int) (
                $_POST["unidade_id"]
                ?? 0
            );

        $usuarioRecebedorId =
            !empty(
                $_POST["usuario_recebedor_id"]
                ?? ""
            )
            ? (int) $_POST["usuario_recebedor_id"]
            : null;

        $descricao =
            trim(
                $_POST["descricao"]
                ?? ""
            );

        $codigoRastreio =
            trim(
                $_POST["codigo_rastreio"]
                ?? ""
            );


        if (
            $unidadeId <= 0
        ) {

            throw new Exception(
                "Selecione uma unidade."
            );
        }


        if (
            $descricao === ""
        ) {

            throw new Exception(
                "Informe a descrição da encomenda."
            );
        }


        $fotoUrl = null;


        if (
            isset(
                $_FILES["foto"]
            ) &&
            $_FILES["foto"]["error"]
                !== UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["foto"]["error"]
                !== UPLOAD_ERR_OK
            ) {

                throw new Exception(
                    "Não foi possível enviar a foto."
                );
            }


            if (
                $_FILES["foto"]["size"]
                > 5 * 1024 * 1024
            ) {

                throw new Exception(
                    "A foto deve possuir no máximo 5 MB."
                );
            }


            $tmp =
                $_FILES["foto"]["tmp_name"];


            $conteudo =
                file_get_contents($tmp);


            if (
                $conteudo === false
            ) {

                throw new Exception(
                    "Não foi possível ler a foto."
                );
            }


            $tipo =
                $_FILES["foto"]["type"]
                ?? "";


            $extensao = null;


            $assinatura =
                substr(
                    $conteudo,
                    0,
                    12
                );


            if (
                str_starts_with(
                    $assinatura,
                    "\xFF\xD8\xFF"
                )
            ) {

                $extensao = "jpg";

            } elseif (
                str_starts_with(
                    $assinatura,
                    "\x89PNG\x0D\x0A\x1A\x0A"
                )
            ) {

                $extensao = "png";

            } elseif (
                str_starts_with(
                    $assinatura,
                    "RIFF"
                ) &&
                substr(
                    $conteudo,
                    8,
                    4
                ) === "WEBP"
            ) {

                $extensao = "webp";
            }


            if (
                $extensao === null
            ) {

                throw new Exception(
                    "Arquivo inválido. Envie uma imagem JPG, PNG ou WEBP."
                );
            }


            $diretorio =
                __DIR__ .
                "/../../assets/img/encomendas";


            if (
                !is_dir($diretorio)
            ) {

                mkdir(
                    $diretorio,
                    0777,
                    true
                );
            }


            $nomeArquivo =
                "encomenda_" .
                date("YmdHis") .
                "_" .
                bin2hex(
                    random_bytes(5)
                ) .
                "." .
                $extensao;


            $caminho =
                $diretorio .
                "/" .
                $nomeArquivo;


            if (
                file_put_contents(
                    $caminho,
                    $conteudo
                ) === false
            ) {

                throw new Exception(
                    "Não foi possível salvar a foto."
                );
            }


            $fotoUrl =
                "../../assets/img/encomendas/" .
                $nomeArquivo;
        }


        $encomenda =
            new EncomendaDTO();


        $encomenda->setUnidadeId(
            $unidadeId
        );


        $encomenda->setUsuarioRecebedorId(
            $usuarioRecebedorId
        );


        $encomenda->setDescricao(
            $descricao
        );


        $encomenda->setCodigoRastreio(
            $codigoRastreio !== ""
                ? $codigoRastreio
                : null
        );


        $encomenda->setFotoUrl(
            $fotoUrl
        );


        $encomenda->setDataRecebimento(
            date("Y-m-d H:i:s")
        );


        $encomenda->setStatus(
            "Aguardando_Retirada"
        );


        if (
            !$controller->cadastrar(
                $encomenda
            )
        ) {

            throw new Exception(
                "Não foi possível cadastrar a encomenda."
            );
        }


        header(
            "Location: index.php?sucesso=cadastrada"
        );

        exit;

    } catch (
        Throwable $e
    ) {

        $erro =
            Erro::mensagem($e);
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
        Nova Encomenda - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand fw-bold"
        >
            📦 Encomendas
        </a>

        <a
            href="/koinon-service/logout.php"
            class="btn btn-outline-light btn-sm"
        >
            Sair
        </a>

    </div>

</nav>


<div class="container py-4">

    <div class="row justify-content-center">

        <div class="col-lg-8">

            <div class="card shadow-sm">

                <div class="card-header">

                    <h4 class="mb-0">

                        📦 Cadastrar encomenda

                    </h4>

                </div>


                <div class="card-body">


                    <?php if (
                        $erro !== null
                    ): ?>

                        <div class="alert alert-danger">

                            ❌

                            <?= htmlspecialchars(
                                $erro
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        enctype="multipart/form-data"
                        id="formEncomenda"
                    >


                        <div class="mb-3">

                            <label
                                for="unidade_id"
                                class="form-label"
                            >

                                Unidade *

                            </label>


                            <select
                                name="unidade_id"
                                id="unidade_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione a unidade
                                </option>


                                <?php foreach (
                                    $unidades
                                    as $unidade
                                ): ?>

                                    <option
                                        value="<?= (int)
                                            $unidade["id"]
                                        ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $unidade["identificacao"]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="mb-3">

                            <label
                                for="usuario_recebedor_id"
                                class="form-label"
                            >

                                Morador

                            </label>


                            <select
                                name="usuario_recebedor_id"
                                id="usuario_recebedor_id"
                                class="form-select"
                                disabled
                            >

                                <option value="">

                                    Selecione primeiro a unidade

                                </option>

                            </select>


                            <div
                                class="form-text"
                            >

                                O morador selecionado ficará associado à encomenda.

                            </div>

                        </div>


                        <div class="mb-3">

                            <label
                                for="descricao"
                                class="form-label"
                            >

                                Descrição da encomenda *

                            </label>


                            <textarea
                                name="descricao"
                                id="descricao"
                                class="form-control"
                                rows="3"
                                maxlength="1000"
                                placeholder="Ex.: Caixa pequena dos Correios"
                                required
                            ></textarea>

                        </div>


                        <div class="mb-3">

                            <label
                                for="codigo_rastreio"
                                class="form-label"
                            >

                                Código de rastreio

                            </label>


                            <input
                                type="text"
                                name="codigo_rastreio"
                                id="codigo_rastreio"
                                class="form-control"
                                maxlength="50"
                                placeholder="Ex.: BR123456789"
                            >

                        </div>


                        <div class="mb-4">

                            <label
                                for="foto"
                                class="form-label"
                            >

                                Foto da encomenda

                            </label>


                            <input
                                type="file"
                                name="foto"
                                id="foto"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                            >


                            <div
                                class="form-text"
                            >

                                JPG, PNG ou WEBP. Máximo de 5 MB.

                            </div>


                            <div
                                id="previewContainer"
                                class="mt-3 d-none"
                            >

                                <p class="mb-2 fw-semibold">

                                    Pré-visualização:

                                </p>

                                <img
                                    id="preview"
                                    src=""
                                    alt="Pré-visualização"
                                    class="img-thumbnail"
                                    style="
                                        max-width:300px;
                                        max-height:300px;
                                        object-fit:cover;
                                    "
                                >

                            </div>

                        </div>


                        <div
                            class="d-flex justify-content-between"
                        >

                            <a
                                href="index.php"
                                class="btn btn-outline-secondary"
                            >

                                ← Voltar

                            </a>


                            <button
                                type="submit"
                                class="btn btn-primary"
                            >

                                📦 Cadastrar encomenda

                            </button>

                        </div>


                    </form>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

const unidade =
    document.getElementById(
        "unidade_id"
    );

const morador =
    document.getElementById(
        "usuario_recebedor_id"
    );


unidade.addEventListener(
    "change",
    async function () {

        const unidadeId =
            this.value;


        morador.innerHTML =
            "<option value=''>Carregando...</option>";

        morador.disabled = true;


        if (!unidadeId) {

            morador.innerHTML =
                "<option value=''>Selecione primeiro a unidade</option>";

            return;
        }


        try {

            const resposta =
                await fetch(
                    "moradores.php?unidade_id=" +
                    encodeURIComponent(
                        unidadeId
                    )
                );


            const dados =
                await resposta.json();


            morador.innerHTML =
                "<option value=''>Selecione o morador</option>";


            if (
                dados.length === 0
            ) {

                morador.innerHTML =
                    "<option value=''>Nenhum morador encontrado</option>";

                return;
            }


            dados.forEach(
                function (item) {

                    const option =
                        document.createElement(
                            "option"
                        );


                    option.value =
                        item.id;


                    option.textContent =
                        item.nome +
                        (
                            item.cpf
                                ? " - " +
                                  item.cpf
                                : ""
                        );


                    morador.appendChild(
                        option
                    );
                }
            );


            morador.disabled = false;

        } catch (
            erro
        ) {

            morador.innerHTML =
                "<option value=''>Erro ao carregar moradores</option>";
        }
    }
);


const foto =
    document.getElementById(
        "foto"
    );

const preview =
    document.getElementById(
        "preview"
    );

const previewContainer =
    document.getElementById(
        "previewContainer"
    );


foto.addEventListener(
    "change",
    function () {

        const arquivo =
            this.files[0];


        if (!arquivo) {

            previewContainer.classList.add(
                "d-none"
            );

            preview.src = "";

            return;
        }


        if (
            arquivo.size >
            5 * 1024 * 1024
        ) {

            alert(
                "A foto deve possuir no máximo 5 MB."
            );

            this.value = "";

            previewContainer.classList.add(
                "d-none"
            );

            return;
        }


        const leitor =
            new FileReader();


        leitor.onload =
            function (evento) {

                preview.src =
                    evento.target.result;

                previewContainer.classList.remove(
                    "d-none"
                );
            };


        leitor.readAsDataURL(
            arquivo
        );
    }
);

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>