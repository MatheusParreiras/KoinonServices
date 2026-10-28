<?php

class NotificacaoDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;
    private ?int $publicacao_id = null;

    private string $tipo = "";
    private string $titulo = "";
    private string $mensagem = "";

    private ?string $link = null;

    private bool $lida = false;

    private ?string $created_at = null;


    // ==========================================================
    // ID
    // ==========================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }


    // ==========================================================
    // USUÁRIO
    // ==========================================================

    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }


    // ==========================================================
    // PUBLICAÇÃO
    // ==========================================================

    public function getPublicacaoId(): ?int
    {
        return $this->publicacao_id;
    }

    public function setPublicacaoId(?int $publicacao_id): void
    {
        $this->publicacao_id = $publicacao_id;
    }


    // ==========================================================
    // TIPO
    // ==========================================================

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): void
    {
        $this->tipo = $tipo;
    }


    // ==========================================================
    // TÍTULO
    // ==========================================================

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }


    // ==========================================================
    // MENSAGEM
    // ==========================================================

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function setMensagem(string $mensagem): void
    {
        $this->mensagem = $mensagem;
    }


    // ==========================================================
    // LINK
    // ==========================================================

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): void
    {
        $this->link = $link;
    }


    // ==========================================================
    // LIDA
    // ==========================================================

    public function isLida(): bool
    {
        return $this->lida;
    }

    public function getLida(): bool
    {
        return $this->lida;
    }

    public function setLida(bool $lida): void
    {
        $this->lida = $lida;
    }


    // ==========================================================
    // DATA
    // ==========================================================

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }
}