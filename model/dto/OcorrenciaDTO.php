<?php

class OcorrenciaDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;
    private ?string $usuario_nome = null;
    private string $tipo = "";
    private string $titulo = "";
    private string $descricao = "";
    private string $status = "Aberta";
    private ?string $foto_url = null;
    private ?string $data_abertura = null;
    private ?string $data_fechamento = null;
    private ?string $historico = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }

    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): void
    {
        $this->descricao = $descricao;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getFotoUrl(): ?string
    {
        return $this->foto_url;
    }

    public function setFotoUrl(?string $foto_url): void
    {
        $this->foto_url = $foto_url;
    }

    public function getDataAbertura(): ?string
    {
        return $this->data_abertura;
    }

    public function setDataAbertura(?string $data_abertura): void
    {
        $this->data_abertura = $data_abertura;
    }

    public function getDataFechamento(): ?string
    {
        return $this->data_fechamento;
    }

    public function setDataFechamento(?string $data_fechamento): void
    {
        $this->data_fechamento = $data_fechamento;
    }

    public function getHistorico(): ?string
    {
        return $this->historico;
    }

    public function setHistorico(?string $historico): void
    {
        $this->historico = $historico;
    }
}
