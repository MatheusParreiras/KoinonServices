<?php

class VeiculoDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;
    private ?string $usuario_nome = null;
    private ?string $unidade_identificacao = null;
    private ?int $unidade_id = null;
    private string $placa = "";
    private string $modelo = "";
    private string $cor = "";
    private string $status_aprovacao = "Pendente";
    private ?string $created_at = null;

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

    public function getUnidadeId(): ?int{return $this->unidade_id;}
    public function setUnidadeId(?int $v):void{$this->unidade_id=$v;}

    public function getUnidadeIdentificacao(): ?string{return $this->unidade_identificacao;}
    public function setUnidadeIdentificacao(?string $v):void{$this->unidade_identificacao=$v;}

    public function getPlaca(): string
    {
        return $this->placa;
    }

    public function setPlaca(string $placa): void
    {
        $this->placa = $placa;
    }

    public function getModelo(): string
    {
        return $this->modelo;
    }

    public function setModelo(string $modelo): void
    {
        $this->modelo = $modelo;
    }

    public function getCor(): string
    {
        return $this->cor;
    }

    public function setCor(string $cor): void
    {
        $this->cor = $cor;
    }

    public function getStatusAprovacao(): string { return $this->status_aprovacao; }
    public function setStatusAprovacao(string $status): void { $this->status_aprovacao = $status; }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }
}