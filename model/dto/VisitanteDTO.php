<?php

class VisitanteDTO
{
    private ?int $id = null;
    private string $nome = "";
    private string $documento = "";
    private ?string $foto_url = null;
    private string $tipo_visitante = "Visitante";
    private ?string $created_at = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }


    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): void
    {
        $this->nome = $nome;
    }


    public function getDocumento(): string
    {
        return $this->documento;
    }

    public function setDocumento(string $documento): void
    {
        $this->documento = $documento;
    }


    public function getFotoUrl(): ?string
    {
        return $this->foto_url;
    }

    public function setFotoUrl(?string $foto_url): void
    {
        $this->foto_url = $foto_url;
    }


    public function getTipoVisitante(): string
    {
        return $this->tipo_visitante;
    }

    public function setTipoVisitante(string $tipo_visitante): void
    {
        $this->tipo_visitante = $tipo_visitante;
    }


    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }
}