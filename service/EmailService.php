<?php

class EmailService
{
    /*
     * ==========================================================
     * CONFIGURAÇÃO
     * ==========================================================
     *
     * Por enquanto o serviço utiliza a função mail() do PHP.
     *
     * O envio real depende da configuração SMTP do XAMPP/PHP.
     *
     * Não colocamos senha ou conta de e-mail diretamente
     * neste arquivo.
     */


    private string $remetente =
        "Koinon Services";


    private string $emailRemetente =
        "noreply@koinonservice.com";


    /* ==========================================================
       ENVIAR E-MAIL
    ========================================================== */

    public function enviar(
        string $destinatario,
        string $assunto,
        string $mensagem
    ): bool {

        if (
            !filter_var(
                $destinatario,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return false;
        }

        if (
            trim($assunto) === ""
            ||
            trim($mensagem) === ""
        ) {
            return false;
        }


        $headers =
            "From: "
            . $this->remetente
            . " <"
            . $this->emailRemetente
            . ">\r\n";

        $headers .=
            "Reply-To: "
            . $this->emailRemetente
            . "\r\n";

        $headers .=
            "MIME-Version: 1.0\r\n";

        $headers .=
            "Content-Type: text/plain; charset=UTF-8\r\n";


        return mail(
            $destinatario,
            $assunto,
            $mensagem,
            $headers
        );
    }
}