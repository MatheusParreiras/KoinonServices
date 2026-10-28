<?php

class UnidadeDTO
{
    private ?int $id = null;
    private string $identificacao = "";
    private string $tipo_unidade = "";
    private float $fracao_ideal = 0.0;
    private int $quantidade_vagas = 0;
    private ?string $created_at = null;

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): void { $this->id = $id; }
    public function getIdentificacao(): string { return $this->identificacao; }
    public function setIdentificacao(string $identificacao): void { $this->identificacao = $identificacao; }
    public function getTipoUnidade(): string { return $this->tipo_unidade; }
    public function setTipoUnidade(string $tipo_unidade): void { $this->tipo_unidade = $tipo_unidade; }
    public function getFracaoIdeal(): float { return $this->fracao_ideal; }
    public function setFracaoIdeal(float $fracao_ideal): void { $this->fracao_ideal = $fracao_ideal; }
    public function getQuantidadeVagas(): int { return $this->quantidade_vagas; }
    public function setQuantidadeVagas(int $quantidade_vagas): void { $this->quantidade_vagas = max(0, min(2, $quantidade_vagas)); }
    public function getCreatedAt(): ?string { return $this->created_at; }
    public function setCreatedAt(?string $created_at): void { $this->created_at = $created_at; }
}
